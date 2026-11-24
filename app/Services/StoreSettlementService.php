<?php

namespace App\Services;

use App\Exceptions\SettlementException;
use App\Models\LoyaltyRedemption;
use App\Models\Store;
use App\Models\StoreSettlementItem;
use App\Models\StoreSettlementPayment;
use App\Models\VoucherPurchaseLedger;
use App\Models\StoreSettlements;
use App\Models\WalletModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Store settlement - redemptions AND voucher purchases (voucher side: see VoucherSettlementLedger).
 *
 * Sign convention for every amount (store's point of view):
 *   +  GoerOne owes the store   (online / wallet redemptions)
 *   -  the store owes GoerOne   (cash redemptions: commission receivable)
 *
 * Formulas (goerone_discount = discountCust - store_discount):
 *   online/wallet (is_cash = 0): order_amount - store_discount - discount - pg_fee
 *   cash          (is_cash = 1): -(discount - goerone_discount)   [pg_fee ignored]
 *
 * Settlement net = SUM(item amounts)
 *   net > 0  -> direction store_receives (recorded payout, no wallet debit)
 *   net < 0  -> direction store_pays     (store wallet is debited, as before)
 *   net = 0  -> closed immediately
 */
class StoreSettlementService
{
    private const TZ = 'Asia/Kolkata';
    public const CALC_VERSION = 2;

    /** reward_wallet_temp statuses that keep a redemption OUT of settlement until it is approved (SUCCESS) */
    public const HOLD_STATUSES = ['PENDING', 'FAILED'];

    /** Approval state from the redemption's reward_wallet_temp row (one row, its status gets updated): PENDING / FAILED / SUCCESS; NONE = no temp row */
    private const TEMP_STATUS_SQL = "CASE
        WHEN EXISTS (SELECT 1 FROM reward_wallet_temp t WHERE t.redemption_id = redemption.redemption_id AND t.status = 'FAILED') THEN 'FAILED'
        WHEN EXISTS (SELECT 1 FROM reward_wallet_temp t WHERE t.redemption_id = redemption.redemption_id AND t.status = 'PENDING') THEN 'PENDING'
        WHEN EXISTS (SELECT 1 FROM reward_wallet_temp t WHERE t.redemption_id = redemption.redemption_id) THEN 'SUCCESS'
        ELSE 'NONE'
    END";

    /**
     * Approval gate for redemption queries.
     * Eligible (default): the redemption has NO PENDING / FAILED row in reward_wallet_temp, i.e. it has no temp row at all
     *                     (directly approved / no cashback) or every temp row is SUCCESS.
     * $held = true: the opposite - redemptions waiting for approval or rejected.
     */
    private static function applyApprovalGate($query, bool $held = false)
    {
        $method = $held ? 'whereExists' : 'whereNotExists';

        return $query->{$method}(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('reward_wallet_temp as rwt')
                ->whereColumn('rwt.redemption_id', 'redemption.redemption_id')
                ->whereIn('rwt.status', self::HOLD_STATUSES);
        });
    }

    /* ------------------------------------------------------------------
     * Money helpers - strings + BCMath only, never floats in arithmetic
     * ------------------------------------------------------------------ */
    public static function dec($value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * Calculate one redemption. Pure function, no DB access.
     */
    public static function calculateRedemption($r): array
    {
        $order      = self::dec($r->order_amount);
        $storeDisc  = self::dec($r->store_discount);
        $custDisc   = self::dec($r->discountCust);
        $commission = self::dec($r->discount);
        $isCash     = (int) $r->is_cash === 1;
        $pgFee      = $isCash ? '0.00' : self::dec($r->pg_fee);

        $goeroneDisc = bcsub($custDisc, $storeDisc, 2);

        if ($isCash) {
            // GoerOne receivable = commission - goerone_discount  => store's signed view is the negative
            $amount = bcsub($goeroneDisc, $commission, 2);
        } else {
            $amount = bcsub(bcsub(bcsub($order, $storeDisc, 2), $commission, 2), $pgFee, 2);
        }

        return [
            'amount'           => $amount,
            'side'             => bccomp($amount, '0', 2) >= 0 ? 'payable' : 'receivable',
            'is_cash'          => $isCash ? 1 : 0,
            'order_amount'     => $order,
            'store_discount'   => $storeDisc,
            'goerone_discount' => $goeroneDisc,
            'commission'       => $commission,
            'pg_fee'           => $pgFee,
        ];
    }

    /* ------------------------------------------------------------------
     * GENERATION
     * ------------------------------------------------------------------ */

    /**
     * Generate settlements for all stores (or one store / one agency).
     * $preview = true calculates and returns results without writing anything.
     */
    public function generateAll(?int $storeId = null, bool $preview = false, ?int $agencyId = null, array $opts = []): array
    {
        $summary = [
            'preview'     => $preview,
            'generated'   => 0,
            'settlements' => [],
            'skipped'     => [],
            'failed'      => [],
        ];

        $stores = Store::query()
            ->when($storeId, fn($q) => $q->where('id', $storeId))
            ->when($agencyId, fn($q) => $q->where('AgencyID', $agencyId))
            ->get();

        foreach ($stores as $store) {
            try {
                $result = $this->generateForStore($store, $preview, $opts);
            } catch (\Throwable $e) {
                Log::error('Settlement generation failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
                $summary['failed'][] = ['store_id' => $store->id, 'message' => 'Generation failed, see logs.'];
                continue;
            }

            if ($result === null) {
                continue; // nothing eligible
            }
            if (!empty($result['skipped'])) {
                $summary['skipped'][] = $result;
                continue;
            }
            $summary['generated']++;
            $summary['settlements'][] = $result;
        }

        return $summary;
    }

    /**
     * $opts (all optional, intended for targeted test runs on ONE store):
     *   from        Y-m-d  only transactions on/after this date
     *   to          Y-m-d  only transactions on/before this date
     *   ignore_due  bool   TESTING ONLY: skip the due-days rule (also picks up not-yet-due transactions)
     * Without $opts the normal rule applies: transaction date + settlement_day <= today (IST).
     * Transactions = redemptions (redemption_date) + paid voucher purchases (paid_at).
     */
    public function generateForStore(Store $store, bool $preview = false, array $opts = []): ?array
    {
        $from      = $opts['from'] ?? null;
        $to        = $opts['to'] ?? null;
        $ignoreDue = !empty($opts['ignore_due']);
        $targeted  = $from || $to || $ignoreDue;

        if (!$ignoreDue && ($store->settlement_day === null || $store->settlement_day === '')) {
            return ['skipped' => true, 'store_id' => $store->id, 'reason' => 'settlement_day not configured'];
        }

        $dueDays = max(0, (int) $store->settlement_day);
        $cutoff  = Carbon::now(self::TZ)->startOfDay()->subDays($dueDays)->toDateString();
        // upper date bound: due-days cutoff, tightened by "to"; with ignore_due only "to" (if any) applies
        $upper   = $ignoreDue ? $to : ($to ? min($to, $cutoff) : $cutoff);

        $eligibleRedemptions = fn() => LoyaltyRedemption::where('stores_id', $store->id)
            ->where('AgencyID', $store->AgencyID)
            ->whereNull('settlement_id')
            // only completed redemptions are settled (not pending / cancelled / refunded)
            ->where('status', 'completed')
            // approval gate: skip redemptions whose reward_wallet_temp row is PENDING / FAILED
            ->where(fn($q) => self::applyApprovalGate($q))
            ->when($upper, fn($q) => $q->whereDate('redemption_date', '<=', $upper))
            ->when($from, fn($q) => $q->whereDate('redemption_date', '>=', $from))
            ->orderBy('redemption_id');

        $eligibleVouchers = fn() => VoucherPurchaseLedger::where('store_id', $store->id)
            ->where('AgencyID', $store->AgencyID)
            ->where('status', 'posted')
            ->whereNull('settlement_id')
            ->when($upper, fn($q) => $q->whereDate('paid_at', '<=', $upper))
            ->when($from, fn($q) => $q->whereDate('paid_at', '>=', $from))
            ->orderBy('id');

        // unsettled transactions inside the requested range that are NOT due yet (shown in preview)
        $notYetDue = function () use ($store, $cutoff, $from, $to, $ignoreDue) {
            if ($ignoreDue) {
                return 0;
            }
            $r = LoyaltyRedemption::where('stores_id', $store->id)
                ->where('AgencyID', $store->AgencyID)
                ->whereNull('settlement_id')
                ->where('status', 'completed')
                ->whereDate('redemption_date', '>', $cutoff)
                ->when($from, fn($q) => $q->whereDate('redemption_date', '>=', $from))
                ->when($to, fn($q) => $q->whereDate('redemption_date', '<=', $to))
                ->count();
            $v = VoucherPurchaseLedger::where('store_id', $store->id)
                ->where('AgencyID', $store->AgencyID)
                ->where('status', 'posted')
                ->whereNull('settlement_id')
                ->whereDate('paid_at', '>', $cutoff)
                ->when($from, fn($q) => $q->whereDate('paid_at', '>=', $from))
                ->when($to, fn($q) => $q->whereDate('paid_at', '<=', $to))
                ->count();
            return $r + $v;
        };

        // due-range redemptions that are held back because their reward_wallet_temp row is PENDING / FAILED
        $onHold = function () use ($store, $upper, $from) {
            $q = LoyaltyRedemption::where('stores_id', $store->id)
                ->where('AgencyID', $store->AgencyID)
                ->whereNull('settlement_id')
                ->when($upper, fn($q) => $q->whereDate('redemption_date', '<=', $upper))
                ->when($from, fn($q) => $q->whereDate('redemption_date', '>=', $from));

            return self::applyApprovalGate($q->where('status', 'completed'), true)->count();
        };

        if ($preview) {
            $reds     = $eligibleRedemptions()->get();
            $vouchers = $eligibleVouchers()->get();
            if ($reds->isEmpty() && $vouchers->isEmpty()) {
                return $targeted
                    ? ['skipped' => true, 'store_id' => $store->id, 'reason' => 'No eligible transactions in this range (' . $notYetDue() . ' not yet due, ' . $onHold() . ' awaiting approval / rejected)']
                    : null;
            }
            $calc = $this->summarise($reds, $vouchers, $dueDays);
            return ['store_id' => $store->id, 'store_name' => $store->store_name, 'preview' => true, 'not_yet_due_count' => $notYetDue(), 'on_hold_count' => $onHold()]
                + $calc['totals'] + ['items' => $calc['items']];
        }

        return DB::transaction(function () use ($store, $eligibleRedemptions, $eligibleVouchers, $dueDays, $targeted) {
            // Serialise generation per store, then lock the candidate rows
            Store::whereKey($store->id)->lockForUpdate()->first();
            $reds     = $eligibleRedemptions()->lockForUpdate()->get();
            $vouchers = $eligibleVouchers()->lockForUpdate()->get();

            if ($reds->isEmpty() && $vouchers->isEmpty()) {
                return $targeted
                    ? ['skipped' => true, 'store_id' => $store->id, 'reason' => 'No eligible transactions in this range']
                    : null;
            }

            $calc   = $this->summarise($reds, $vouchers, $dueDays);
            $totals = $calc['totals'];
            $net    = $totals['net_amount'];
            $isZero = bccomp($net, '0', 2) === 0;

            $dates = collect($calc['items'])->pluck('txn_date')->map(fn($d) => Carbon::parse($d)->toDateString());

            $settlement = StoreSettlements::create([
                'store_id'               => $store->id,
                'AgencyID'               => $store->AgencyID,
                'customer_id'            => $store->UserSysId,
                'settlement_no'          => 'STL' . date('YmdHis') . str_pad((string) $store->id, 5, '0', STR_PAD_LEFT),
                'from_date'              => $dates->min(),
                'to_date'                => $dates->max(),
                'total_sales'            => $totals['total_sales'],
                'total_discount'         => $totals['total_commission'],
                'payable_amount'         => ltrim($net, '-'),
                'redem_count'            => $totals['redemption_count'],
                'voucher_count'          => $totals['voucher_count'],
                'voucher_payable'        => $totals['voucher_payable'],
                'voucher_commission'     => $totals['voucher_commission'],
                'status'                 => $isZero ? 'paid' : 'pending',
                'settled_at'             => $isZero ? now() : null,
                'calc_version'           => self::CALC_VERSION,
                'direction'              => bccomp($net, '0', 2) >= 0 ? 'store_receives' : 'store_pays',
                'payable_to_store'       => $totals['payable_to_store'],
                'receivable_from_store'  => $totals['receivable_from_store'],
                'net_amount'             => $net,
                'paid_amount'            => '0.00',
                'total_pg_fee'           => $totals['total_pg_fee'],
                'total_store_discount'   => $totals['total_store_discount'],
                'total_goerone_discount' => $totals['total_goerone_discount'],
                'run_date'               => Carbon::now(self::TZ)->toDateString(),
            ]);

            $now = now();
            $itemRows = [];
            foreach ($calc['items'] as $it) {
                $itemRows[] = [
                    'settlement_id'    => $settlement->id,
                    'redemption_id'    => $it['redemption_id'],
                    'item_type'        => $it['item_type'],
                    'source_id'        => $it['source_id'],
                    'entry_kind'       => 'original',
                    'amount'           => $it['amount'],
                    'side'             => $it['side'],
                    'is_cash'          => $it['is_cash'],
                    'order_amount'     => $it['order_amount'],
                    'store_discount'   => $it['store_discount'],
                    'goerone_discount' => $it['goerone_discount'],
                    'commission'       => $it['commission'],
                    'pg_fee'           => $it['pg_fee'],
                    'ref_no'           => $it['ref_no'],
                    'txn_date'         => $it['txn_date'],
                    'description'      => $it['description'],
                    'eligible_on'      => $it['eligible_on'],
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            foreach (array_chunk($itemRows, 500) as $chunk) {
                StoreSettlementItem::insert($chunk); // unique index uq_ssi_source blocks duplicates
            }

            // Link sources; guard + verify so nothing is ever linked to two settlements
            $linked = 0;
            foreach (array_chunk($reds->pluck('redemption_id')->all(), 1000) as $ids) {
                $linked += LoyaltyRedemption::whereIn('redemption_id', $ids)
                    ->whereNull('settlement_id')
                    ->update(['settlement_id' => $settlement->id]);
            }
            if ($linked !== $reds->count()) {
                throw new \RuntimeException('Redemption link mismatch; settlement rolled back.');
            }

            $vLinked = 0;
            foreach (array_chunk($vouchers->pluck('id')->all(), 1000) as $ids) {
                $vLinked += VoucherPurchaseLedger::whereIn('id', $ids)
                    ->where('status', 'posted')
                    ->whereNull('settlement_id')
                    ->update(['settlement_id' => $settlement->id]);
            }
            if ($vLinked !== $vouchers->count()) {
                throw new \RuntimeException('Voucher link mismatch; settlement rolled back.');
            }

            if ($isZero) {
                $this->finalizeRedemptions($settlement->id, null);
            }

            return ['store_id' => $store->id, 'store_name' => $store->store_name, 'settlement_id' => $settlement->id, 'settlement_no' => $settlement->settlement_no] + $totals;
        });
    }

    private function summarise(Collection $redemptions, Collection $vouchers, int $dueDays): array
    {
        $items = [];
        $t = [
            'payable_to_store' => '0.00', 'receivable_from_store' => '0.00', 'net_amount' => '0.00',
            'total_sales' => '0.00', 'total_commission' => '0.00', 'total_pg_fee' => '0.00',
            'total_store_discount' => '0.00', 'total_goerone_discount' => '0.00',
            'voucher_payable' => '0.00', 'voucher_commission' => '0.00',
            'redemption_count' => 0, 'voucher_count' => 0, 'count' => 0,
        ];

        $push = function (array $c) use (&$items, &$t) {
            $items[] = $c;

            if ($c['side'] === 'payable') {
                $t['payable_to_store'] = bcadd($t['payable_to_store'], $c['amount'], 2);
            } else {
                $t['receivable_from_store'] = bcadd($t['receivable_from_store'], ltrim($c['amount'], '-'), 2);
            }
            $t['net_amount']             = bcadd($t['net_amount'], $c['amount'], 2);
            $t['total_sales']            = bcadd($t['total_sales'], $c['order_amount'], 2);
            $t['total_commission']       = bcadd($t['total_commission'], $c['commission'], 2);
            $t['total_pg_fee']           = bcadd($t['total_pg_fee'], $c['pg_fee'], 2);
            $t['total_store_discount']   = bcadd($t['total_store_discount'], $c['store_discount'], 2);
            $t['total_goerone_discount'] = bcadd($t['total_goerone_discount'], $c['goerone_discount'], 2);
            $t['count']++;
        };

        foreach ($redemptions as $r) {
            $c = self::calculateRedemption($r);
            $c += [
                'item_type'     => 'redemption',
                'source_id'     => $r->redemption_id,
                'redemption_id' => $r->redemption_id,
                'ref_no'        => $r->referneceNo ?? null,
                'txn_date'      => Carbon::parse($r->redemption_date)->toDateTimeString(),
                'description'   => null,
                'eligible_on'   => Carbon::parse($r->redemption_date)->startOfDay()->addDays($dueDays)->toDateString(),
            ];
            $push($c);
            $t['redemption_count']++;
        }

        foreach ($vouchers as $v) {
            $amount = self::dec($v->store_payable);
            $push([
                'item_type'        => 'voucher_purchase',
                'source_id'        => $v->id,
                'redemption_id'    => null,
                'amount'           => $amount,
                'side'             => bccomp($amount, '0', 2) >= 0 ? 'payable' : 'receivable',
                'is_cash'          => null,
                'order_amount'     => self::dec($v->face_value),
                'store_discount'   => self::dec($v->store_discount),
                'goerone_discount' => self::dec($v->admin_discount),
                'commission'       => self::dec($v->commission_amount),
                'pg_fee'           => self::dec($v->pg_fee),
                'ref_no'           => $v->order_no,
                'txn_date'         => Carbon::parse($v->paid_at)->toDateTimeString(),
                'description'      => trim(($v->voucher_name ?? 'Voucher') . ' x ' . $v->quantity),
                'eligible_on'      => Carbon::parse($v->paid_at)->startOfDay()->addDays($dueDays)->toDateString(),
            ]);
            $t['voucher_count']++;
            $t['voucher_payable']    = bcadd($t['voucher_payable'], $amount, 2);
            $t['voucher_commission'] = bcadd($t['voucher_commission'], self::dec($v->commission_amount), 2);
        }

        return ['items' => $items, 'totals' => $t];
    }

    /* ------------------------------------------------------------------
     * DUE TRANSACTIONS
     * Redemptions whose settlement day has passed but that are not in a settlement yet,
     * with their reward_wallet_temp approval status (PENDING / FAILED / SUCCESS / NONE).
     * $agencyId scopes the agency, $storeId (optional) a single store.
     * $f: status (PENDING|FAILED|SUCCESS|NONE), keyword, from, to (Y-m-d, redemption date)
     * ------------------------------------------------------------------ */
    public function dueTransactions(?int $agencyId, ?int $storeId, array $f = [], int $perPage = 25): array
    {
        $today     = Carbon::now(self::TZ)->toDateString();
        $statusSql = self::TEMP_STATUS_SQL;

        $base = function () use ($agencyId, $storeId, $f, $today) {
            return LoyaltyRedemption::query()
                ->join('stores', 'stores.id', '=', 'redemption.stores_id')
                ->whereNull('redemption.settlement_id')
                // same rule as generation: only completed redemptions can ever settle
                ->where('redemption.status', 'completed')
                ->when($agencyId, fn($q) => $q->where('redemption.AgencyID', $agencyId))
                ->when($storeId, fn($q) => $q->where('redemption.stores_id', $storeId))
                // same due rule as generation: stores without a settlement_day are never due
                ->whereNotNull('stores.settlement_day')
                ->where('stores.settlement_day', '<>', '')
                ->whereRaw('DATE(redemption.redemption_date) <= DATE_SUB(?, INTERVAL CAST(stores.settlement_day AS UNSIGNED) DAY)', [$today])
                ->when(!empty($f['from']), fn($q) => $q->whereDate('redemption.redemption_date', '>=', $f['from']))
                ->when(!empty($f['to']), fn($q) => $q->whereDate('redemption.redemption_date', '<=', $f['to']))
                ->when(!empty($f['keyword']), function ($q) use ($f) {
                    $kw = '%' . addcslashes(trim($f['keyword']), '\\%_') . '%';
                    $q->where(fn($w) => $w->where('redemption.referneceNo', 'like', $kw)->orWhere('stores.store_name', 'like', $kw));
                });
        };

        $list = $base()
            ->select([
                'redemption.redemption_id',
                'redemption.referneceNo',
                'redemption.redemption_date',
                'redemption.stores_id',
                'redemption.order_amount',
                'redemption.discount',
                'redemption.is_cash',
                'stores.store_name',
                'stores.settlement_day',
            ])
            ->selectRaw('DATE_ADD(DATE(redemption.redemption_date), INTERVAL CAST(stores.settlement_day AS UNSIGNED) DAY) AS eligible_on')
            ->selectRaw("({$statusSql}) AS temp_status")
            ->when(!empty($f['status']), fn($q) => $q->whereRaw("({$statusSql}) = ?", [$f['status']]))
            ->orderByDesc('redemption.redemption_date')
            ->orderByDesc('redemption.redemption_id')
            ->paginate($perPage);

        // counts per status over the whole due set (ignores the status filter, so the cards stay stable)
        $rows = DB::query()
            ->fromSub($base()->selectRaw("({$statusSql}) AS temp_status")->addSelect('redemption.order_amount'), 'd')
            ->selectRaw('d.temp_status, COUNT(*) AS cnt, COALESCE(SUM(d.order_amount), 0) AS amt')
            ->groupBy('d.temp_status')
            ->get()
            ->keyBy('temp_status');

        $summary = [];
        foreach (['PENDING', 'FAILED', 'SUCCESS', 'NONE'] as $k) {
            $summary[strtolower($k)] = [
                'count'  => (int) ($rows[$k]->cnt ?? 0),
                'amount' => self::dec($rows[$k]->amt ?? 0),
            ];
        }

        return ['data' => $list, 'summary' => $summary];
    }

    /* ------------------------------------------------------------------
     * PAYMENT  (full, partial and bulk all go through pay())
     * ------------------------------------------------------------------ */

    public function markPaid(int $settlementId, object $user, string $paymentMode, string $referenceNo, ?string $remarks)
    {
        return $this->pay($settlementId, $user, null, $paymentMode, $referenceNo, $remarks);
    }

    public function partialPayment(int $settlementId, object $user, $amount, string $paymentMode, string $referenceNo, ?string $remarks)
    {
        return $this->pay($settlementId, $user, self::dec($amount), $paymentMode, $referenceNo, $remarks);
    }

    public function bulkPaid(array $settlementIds, object $user, string $paymentMode, string $referenceNo, ?string $remarks)
    {
        $results = [];
        $failed  = 0;

        foreach (array_unique($settlementIds) as $id) {
            // each settlement is its own transaction: one failure never leaves another half-done
            $res = $this->pay((int) $id, $user, null, $paymentMode, $referenceNo, $remarks);
            $ok  = !empty($res['status']['success']);
            if (!$ok) {
                $failed++;
            }
            $results[$id] = ['success' => $ok, 'message' => $res['message'] ?? ''];
        }

        return [
            'status' => [
                'success'    => $failed === 0,
                'httpStatus' => $failed === 0 ? 200 : 422,
            ],
            'message' => $failed === 0
                ? 'Settlement Paid Successfully'
                : "{$failed} settlement(s) could not be paid. See results.",
            'results' => $results,
        ];
    }

    /**
     * $amount = null pays the full outstanding balance.
     */
    public function pay(int $settlementId, object $user, ?string $amount, string $paymentMode, string $referenceNo, ?string $remarks): array
    {
        try {
            $agencyId = ($user->UserType == 1) ? $user->id : $user->AgencyID;

            $settlement = DB::transaction(function () use ($settlementId, $user, $agencyId, $amount, $paymentMode, $referenceNo, $remarks) {
                $s = StoreSettlements::whereKey($settlementId)->lockForUpdate()->first();

                if (!$s || (int) $s->AgencyID !== (int) $agencyId) {
                    throw new SettlementException('Settlement not found.', 404);
                }
                if (!in_array($s->status, ['pending', 'partial'], true)) {
                    throw new SettlementException('Settlement already processed.', 409);
                }

                $payable = self::dec($s->payable_amount);
                $paid    = self::dec($s->paid_amount);
                $balance = bcsub($payable, $paid, 2);
                $amt     = $amount === null ? $balance : self::dec($amount);

                if (bccomp($amt, '0', 2) <= 0) {
                    throw new SettlementException('Payment amount must be greater than zero.', 422);
                }
                if (bccomp($amt, $balance, 2) > 0) {
                    throw new SettlementException("Amount exceeds the outstanding balance of {$balance}.", 422);
                }
                if (StoreSettlementPayment::where('settlement_id', $s->id)->where('reference_no', $referenceNo)->exists()) {
                    throw new SettlementException('A payment with this reference number already exists for this settlement.', 409);
                }

                // Legacy (v1) settlements and v2 "store_pays" settlements collect from the store wallet
                $storePays = ((int) $s->calc_version === 1) || $s->direction === 'store_pays';

                if ($storePays) {
                    $wb         = WalletModel::WalletBalance($user, $s->customer_id);
                    $walletBal  = self::dec($wb['balances']['wallet_balance'] ?? 0);
                    if (bccomp($walletBal, $amt, 2) < 0) {
                        throw new SettlementException('Insufficient balance : Please contact the Business Team to topup the account.', 1015);
                    }

                    $book = WalletModel::bookingUsingWalletBalance($user, [
                        'customer_id' => $s->customer_id,
                        'amount'      => $amt,
                        'RefrenceNo'  => $referenceNo,
                        'PlanType'    => 10,
                        'Remark'      => $remarks ?: 'Settlement Done',
                        'PaymentMode' => 'Paid For Order',
                    ]);
                    if (empty($book['status']['success'])) {
                        throw new SettlementException('Unable to debit from wallet. please try again.', 1015);
                    }
                }

                StoreSettlementPayment::create([
                    'AgencyID'      => $agencyId,
                    'UserSysId'     => $user->id,
                    'settlement_id' => $s->id,
                    'amount'        => $amt,
                    'payment_mode'  => $paymentMode,
                    'reference_no'  => $referenceNo,
                    'remarks'       => $remarks,
                    'payment_date'  => now(),
                    'direction'     => $storePays ? 'store_pays' : 'store_receives',
                ]);

                $newPaid = bcadd($paid, $amt, 2);

                if (bccomp($newPaid, $payable, 2) >= 0) {
                    $s->update([
                        'paid_amount' => $newPaid,
                        'status'      => 'paid',
                        'settled_by'  => $user->id,
                        'settled_at'  => now(),
                    ]);
                    $this->finalizeRedemptions($s->id, $user->id);
                } else {
                    $s->update(['paid_amount' => $newPaid, 'status' => 'partial']);
                }

                return $s;
            });

            return [
                'status'  => ['success' => true, 'httpStatus' => 200],
                'message' => $settlement->status === 'paid' ? 'Settlement Paid Successfully' : 'Payment Added Successfully',
                'data'    => $settlement->fresh(),
            ];
        } catch (SettlementException $e) {
            return [
                'status'  => ['success' => false, 'httpStatus' => $e->httpStatus],
                'message' => $e->getMessage(),
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            Log::error('Settlement payment failed', ['settlement_id' => $settlementId, 'error' => $e->getMessage()]);
            return [
                'status'  => ['success' => false, 'httpStatus' => 500],
                'message' => 'Unable to process settlement payment. Please try again.',
                'data'    => null,
            ];
        }
    }

    /**
     * Undo an UNPAID settlement made by the new method (testing / mistakes):
     * unlinks its redemptions, removes its items and deletes the header.
     * Refused if anything was paid or the settlement is already closed.
     */
    public function cancelUnpaid(int $settlementId, ?int $agencyId = null): array
    {
        return DB::transaction(function () use ($settlementId, $agencyId) {
            $s = StoreSettlements::whereKey($settlementId)->lockForUpdate()->first();

            if (!$s || ($agencyId !== null && (int) $s->AgencyID !== $agencyId)) {
                throw new SettlementException('Settlement not found.', 404);
            }
            if ((int) $s->calc_version !== self::CALC_VERSION) {
                throw new SettlementException('Only settlements created by the new method can be cancelled.', 422);
            }
            if ($s->status !== 'pending' || bccomp(self::dec($s->paid_amount), '0', 2) !== 0 || $s->payments()->exists()) {
                throw new SettlementException('Only unpaid, pending settlements can be cancelled.', 422);
            }

            $unlinked = LoyaltyRedemption::where('settlement_id', $s->id)->update(['settlement_id' => null]);
            $unlinked += VoucherPurchaseLedger::where('settlement_id', $s->id)->update(['settlement_id' => null]);
            StoreSettlementItem::where('settlement_id', $s->id)->delete();
            $no = $s->settlement_no;
            $s->delete();

            return ['settlement_no' => $no, 'unlinked' => $unlinked];
        });
    }

    /**
     * Mark every redemption of a fully paid settlement as settled, with its own item amount.
     */
    private function finalizeRedemptions(int $settlementId, ?int $userId): void
    {
        DB::update(
            "UPDATE redemption r
             JOIN store_settlement_items i
               ON i.item_type = 'redemption' AND i.source_id = r.redemption_id
             SET r.settlement_status = 'settled',
                 r.settlement_amount = i.amount,
                 r.settlement_by = ?
             WHERE i.settlement_id = ?",
            [$userId, $settlementId]
        );

        VoucherPurchaseLedger::where('settlement_id', $settlementId)
            ->update(['status' => 'settled', 'settled_at' => now()]);
    }
}