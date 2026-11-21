<?php

namespace App\Services;

use App\Exceptions\SettlementException;
use App\Models\VoucherOrder;
use App\Models\VoucherPurchaseLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Voucher purchase side of store settlement.
 *
 * Per paid order item (all amounts for the whole line):
 *   face_value      = unit_price x quantity
 *   customer_paid   = face_value - customer_discount          (tax excluded)
 *   store_discount  = customer_discount if discount_source = 1 (store funded) else 0
 *   admin_discount  = customer_discount if discount_source = 0 (GoerOne funded) else 0
 *   commission      = on FACE VALUE: percentage -> face x value / 100, fixed -> value x quantity
 *   store_payable   = face_value - store_discount - commission - pg_fee
 *
 * Check with the spec: face 1000, discount 20, commission 5%
 *   admin funded : 1000 - 0  - 50 = 950   (customer 980 + GoerOne 20 - 50)
 *   store funded : 1000 - 20 - 50 = 930   (customer 980 - 50)
 */
class VoucherSettlementLedger
{
    private static function dec($v): string
    {
        return StoreSettlementService::dec($v);
    }

    /** half-up rounding to 2 decimals of a bcmath string that has more decimals */
    private static function round2(string $x): string
    {
        $adj = bccomp($x, '0', 6) >= 0 ? '0.005' : '-0.005';
        return bcadd(bcadd($x, $adj, 6), '0', 2);
    }

    /** Commission of one order line, on face value. Called at checkout and stored on the item. */
    public static function commissionFor($voucher, int $qty, $lineFace): string
    {
        $value = self::dec($voucher->comission_value ?? 0);
        if (bccomp($value, '0', 2) <= 0) {
            return '0.00';
        }

        if ((int) ($voucher->comission_type ?? 0) === 1) { // fixed, per voucher
            return self::round2(bcmul($value, (string) $qty, 6));
        }

        // percentage of the line's face value
        return self::round2(bcdiv(bcmul(self::dec($lineFace), $value, 6), '100', 6));
    }

    /** Platform fee of one order line (percentage of face value), only when the store is the payer. */
    public static function pgFeeFor($store, $lineFace): string
    {
        if (! $store || strtolower((string) $store->platform_fee_payer) !== 'store') {
            return '0.00';
        }

        $pct = self::dec($store->platform_fee ?? 0);
        if (bccomp($pct, '0', 2) <= 0) {
            return '0.00';
        }

        return self::round2(bcdiv(bcmul(self::dec($lineFace), $pct, 6), '100', 6));
    }

    /** Pure calculation of one ledger line. */
    public static function calculate(string $face, string $customerDiscount, int $source, string $commission, string $pgFee): array
    {
        $storeDiscount = $source === 1 ? $customerDiscount : '0.00';
        $adminDiscount = $source === 1 ? '0.00' : $customerDiscount;

        $payable = bcsub(bcsub(bcsub($face, $storeDiscount, 2), $commission, 2), $pgFee, 2);

        return [
            'face_value'     => $face,
            'customer_paid'  => bcsub($face, $customerDiscount, 2),
            'store_discount' => $storeDiscount,
            'admin_discount' => $adminDiscount,
            'store_payable'  => $payable,
        ];
    }

    /**
     * Post the ledger rows of a PAID order. Idempotent (one row per order item).
     * Call inside the same transaction that marks the order paid.
     *
     * @return int number of rows posted
     */
    public function postOrder(VoucherOrder $order): int
    {
        if ($order->status !== 'paid') {
            return 0;
        }

        $order->loadMissing('items.voucher');
        $items = $order->items;
        if ($items->isEmpty()) {
            return 0;
        }

        // order level gateway fee, split across items by face value (last item takes the rounding difference)
        $pgTotal   = self::dec($order->getAttribute('pg_fee'));
        $faceTotal = '0.00';
        foreach ($items as $item) {
            $faceTotal = bcadd($faceTotal, self::dec($item->line_total), 2);
        }

        $remainingPg = $pgTotal;
        $count       = $items->count();
        $index       = 0;
        $posted      = 0;

        foreach ($items as $item) {
            $index++;

            if ($index === $count) {
                $pg = $remainingPg;
            } else {
                $pg = bccomp($faceTotal, '0', 2) > 0
                    ? self::round2(bcdiv(bcmul($pgTotal, self::dec($item->line_total), 6), $faceTotal, 6))
                    : '0.00';
                $remainingPg = bcsub($remainingPg, $pg, 2);
            }

            if (VoucherPurchaseLedger::where('voucher_order_item_id', $item->id)->exists()) {
                continue;
            }

            if ($item->customer_discount === null || !$item->voucher || empty($item->voucher->store_id)) {
                Log::warning('Voucher ledger: item skipped (no settlement snapshot or no store)', [
                    'order' => $order->order_no,
                    'item'  => $item->id,
                ]);
                continue;
            }

            $face       = self::dec($item->line_total);
            $custDisc   = self::dec($item->customer_discount);
            $source     = (int) ($item->discount_source ?? 0) === 1 ? 1 : 0;
            $commission = self::dec($item->commission_amount);
            $pg         = self::dec($item->pg_fee); 
            $calc       = self::calculate($face, $custDisc, $source, $commission, $pg);

            VoucherPurchaseLedger::create([
                'AgencyID'              => $item->AgencyID,
                'store_id'              => $item->voucher->store_id,
                'voucher_id'            => $item->voucher_id,
                'voucher_order_id'      => $order->id,
                'voucher_order_item_id' => $item->id,
                'order_no'              => $order->order_no,
                'voucher_name'          => $item->voucher_name,
                'customer_id'           => $order->customer_id,
                'quantity'              => $item->quantity,
                'face_value'            => $calc['face_value'],
                'customer_discount'     => $custDisc,
                'customer_paid'         => $calc['customer_paid'],
                'discount_source'       => $source,
                'store_discount'        => $calc['store_discount'],
                'admin_discount'        => $calc['admin_discount'],
                'commission_type'       => $item->comission_type,
                'commission_value'      => $item->comission_value,
                'commission_amount'     => $commission,
                'pg_fee'                => $pg,
                'store_payable'         => $calc['store_payable'],
                'paid_at'               => $order->paid_at ?? now(),
                'status'                => 'posted',
            ]);
            $posted++;
        }

        return $posted;
    }

    /**
     * Safety net: post paid orders that have no ledger rows yet (e.g. posting failed once).
     * Orders without a settlement snapshot (placed before the checkout change) are skipped with a warning.
     */
    public function postMissing(?string $since = null): array
    {
        $since = $since ?: now()->subDays(7)->toDateString();

        $orders = VoucherOrder::where('status', 'paid')
            ->whereDate('paid_at', '>=', $since)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('voucher_purchase_ledger')
                    ->whereColumn('voucher_purchase_ledger.voucher_order_id', 'voucher_orders.id');
            })
            ->orderBy('id')
            ->get();

        $result = ['orders' => $orders->count(), 'rows' => 0, 'failed' => 0];

        foreach ($orders as $order) {
            try {
                $result['rows'] += DB::transaction(fn() => $this->postOrder($order));
            } catch (\Throwable $e) {
                $result['failed']++;
                Log::error('Voucher ledger sync failed', ['order' => $order->order_no, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * Exclude a refunded / cancelled order from settlement.
     * Allowed only while none of its rows is in a settlement; afterwards it needs a manual adjustment.
     */
    public function reverseOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            $rows = VoucherPurchaseLedger::where('voucher_order_id', $orderId)->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                return 0;
            }
            foreach ($rows as $r) {
                if ($r->status === 'settled' || $r->settlement_id) {
                    throw new SettlementException('This order is already part of a settlement and needs a manual adjustment.', 422);
                }
            }

            return VoucherPurchaseLedger::where('voucher_order_id', $orderId)->update(['status' => 'reversed']);
        });
    }
}