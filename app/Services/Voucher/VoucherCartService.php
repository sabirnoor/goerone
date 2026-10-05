<?php

namespace App\Services\Voucher;

use App\Models\VoucherCartItem;

class VoucherCartService
{
    public function summary(int $customerId): array
    {
        $items    = VoucherCartItem::with('voucher')->where('customer_id', $customerId)->get();
        $subtotal = 0;
        $hasIssue = false;

        $rows = $items->map(function ($ci) use (&$subtotal, &$hasIssue) {
            $v     = $ci->voucher;
            $issue = null;

            if (! $v || ! $v->isPurchasable()) {
                $issue = 'No longer available';
            } elseif (($left = $v->remainingStock()) !== null && $ci->quantity > $left) {
                $issue = "Only {$left} left";
            }

            $line = $v ? round($v->voucher_price * $ci->quantity, 2) : 0;

            if ($issue) {
                $hasIssue = true;
            } else {
                $subtotal += $line;
            }

            return [
                'voucher_id'   => $ci->voucher_id,
                'voucher_name' => $v?->voucher_name,
                'unit_price'   => $v ? (float) $v->voucher_price : 0,
                'quantity'     => $ci->quantity,
                'line_total'   => $line,
                'valid_to'     => $v?->valid_to,
                'issue'        => $issue,
            ];
        });

        $subtotal = round($subtotal, 2);
        $tax      = round($subtotal * config('voucher.gst_percent') / 100, 2);

        return [
            'items'        => $rows->values(),
            'item_count'   => (int) $items->sum('quantity'),
            'subtotal'     => $subtotal,
            'tax_amount'   => $tax,
            'total_amount' => round($subtotal + $tax, 2),
            'can_checkout' => $items->isNotEmpty() && ! $hasIssue,
        ];
    }
}
