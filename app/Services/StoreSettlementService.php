<?php

namespace App\Services;

use App\Exceptions\SettlementException;
use App\Models\LoyaltyRedemption;
use App\Models\Store;
use App\Models\StoreSettlementItem;
use App\Models\StoreSettlementPayment;
use App\Models\StoreSettlements;
use App\Models\WalletModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Store settlement - REDEMPTIONS ONLY (vouchers to be added later).
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
     *   from        Y-m-d  only redemptions on/after this date
     *   to          Y-m-d  only redemptions on/before this date
     *   ignore_due  bool   TESTING ONLY: skip the due-days rule (also picks up not-yet-due transactions)
     * Without $opts the normal rule applies: redemption_date + settlement_day <= today (IST).
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

        $eligible = fn() => LoyaltyRedemption::where('stores_id', $store->id)
            ->where('AgencyID', $store->AgencyID)
            ->whereNull('settlement_id')
            ->when($upper, fn($q) => $q->whereDate('redemption_date', '<=', $upper))
            ->when($from, fn($q) => $q->whereDate('redemption_date', '>=', $from))
            ->orderBy('redemption_id');

        // unsettled rows inside the requested range that are NOT due yet (shown in preview)
        $notYetDue = fn() => $ignoreDue ? 0 : LoyaltyRedemption::where('stores_id', $store->id)
            ->where('AgencyID', $store->AgencyID)
            ->whereNull('settlement_id')
            ->whereDate('redemption_date', '>', $cutoff)
            ->when($from, fn($q) => $q->whereDate('redemption_date', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('redemption_date', '<=', $to))
            ->count();

        if ($preview) {
            $rows = $eligible()->get();
            if ($rows->isEmpty()) {
                return $targeted
                    ? ['skipped' => true, 'store_id' => $store->id, 'reason' => 'No eligible redemptions in this range (' . $notYetDue() . ' not yet due)']
                    : null;
            }
            $calc = $this->summarise($rows, $dueDays);
            return ['store_id' => $store->id, 'store_name' => $store->store_name, 'preview' => true, 'not_yet_due_count' => $notYetDue()]
                + $calc['totals'] + ['items' => $calc['items']];
        }

        return DB::transaction(function () use ($store, $eligible, $dueDays, $targeted) {
            // Serialise generation per store, then lock the candidate rows
            Store::whereKey($store->id)->lockForUpdate()->first();
            $rows = $eligible()->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                return $targeted
                    ? ['skipped' => true, 'store_id' => $store->id, 'reason' => 'No eligible redemptions in this range']
                    : null;
            }

            $calc   = $this->summarise($rows, $dueDays);
            $totals = $calc['totals'];
            $net    = $totals['net_amount'];
            $isZero = bccomp($net, '0', 2) === 0;

            $settlement = StoreSettlements::create([
                'store_id'               => $store->id,
                'AgencyID'               => $store->AgencyID,
                'customer_id'            => $store->UserSysId,
                'settlement_no'          => 'STL' . date('YmdHis') . str_pad((string) $store->id, 5, '0', STR_PAD_LEFT),
                'from_date'              => $rows->min('redemption_date'),
                'to_date'                => $rows->max('redemption_date'),
                'total_sales'            => $totals['total_sales'],
                'total_discount'         => $totals['total_commission'],
                'payable_amount'         => ltrim($net, '-'),
                'redem_count'            => $rows->count(),
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
                    'item_type'        => 'redemption',
                    'source_id'        => $it['redemption_id'],
                    'entry_kind'       => 'original',
                    'amount'           => $it['amount'],
                    'side'             => $it['side'],
                    'is_cash'          => $it['is_cash'],
                    'order_amount'     => $it['order_amount'],
                    'store_discount'   => $it['store_discount'],
                    'goerone_discount' => $it['goerone_discount'],
                    'commission'       => $it['commission'],
                    'pg_fee'           => $it['pg_fee'],
                    'eligible_on'      => $it['eligible_on'],
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            foreach (array_chunk($itemRows, 500) as $chunk) {
                StoreSettlementItem::insert($chunk); // unique index uq_ssi_source blocks duplicates
            }

            // Link redemptions; guard + verify so nothing is ever linked to two settlements
            $linked = 0;
            foreach (array_chunk($rows->pluck('redemption_id')->all(), 1000) as $ids) {
                $linked += LoyaltyRedemption::whereIn('redemption_id', $ids)
                    ->whereNull('settlement_id')
                    ->update(['settlement_id' => $settlement->id]);
            }
            if ($linked !== $rows->count()) {
                throw new \RuntimeException('Redemption link mismatch; settlement rolled back.');
            }

            if ($isZero) {
                $this->finalizeRedemptions($settlement->id, null);
            }

            return ['store_id' => $store->id, 'store_name' => $store->store_name, 'settlement_id' => $settlement->id, 'settlement_no' => $settlement->settlement_no] + $totals;
        });
    }

    private function summarise(Collection $rows, int $dueDays): array
    {
        $items = [];
        $t = [
            'payable_to_store' => '0.00', 'receivable_from_store' => '0.00', 'net_amount' => '0.00',
            'total_sales' => '0.00', 'total_commission' => '0.00', 'total_pg_fee' => '0.00',
            'total_store_discount' => '0.00', 'total_goerone_discount' => '0.00', 'count' => 0,
        ];

        foreach ($rows as $r) {
            $c = self::calculateRedemption($r);
            $c['redemption_id'] = $r->redemption_id;
            $c['redemption_date'] = Carbon::parse($r->redemption_date)->toDateString();
            $c['eligible_on']   = Carbon::parse($r->redemption_date)->startOfDay()->addDays($dueDays)->toDateString();
            $items[] = $c;

            if ($c['side'] === 'payable') {
                $t['payable_to_store'] = bcadd($t['payable_to_store'], $c['amount'], 2);
            } else {
                $t['receivable_from_store'] = bcadd($t['receivable_from_store'], ltrim($c['amount'], '-'), 2);
            }
            $t['net_amount']              = bcadd($t['net_amount'], $c['amount'], 2);
            $t['total_sales']             = bcadd($t['total_sales'], $c['order_amount'], 2);
            $t['total_commission']        = bcadd($t['total_commission'], $c['commission'], 2);
            $t['total_pg_fee']            = bcadd($t['total_pg_fee'], $c['pg_fee'], 2);
            $t['total_store_discount']    = bcadd($t['total_store_discount'], $c['store_discount'], 2);
            $t['total_goerone_discount']  = bcadd($t['total_goerone_discount'], $c['goerone_discount'], 2);
            $t['count']++;
        }

        return ['items' => $items, 'totals' => $t];
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
    }
}