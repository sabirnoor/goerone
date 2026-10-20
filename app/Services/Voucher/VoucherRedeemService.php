<?php

namespace App\Services\Voucher;

use App\Exceptions\VoucherException;
use App\Models\CustomerVoucher;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VoucherRedeemService
{
    /**
     * Read-only check: is this code usable right now for this customer/order?
     * Returns the voucher + computed discount. Does NOT lock or consume it.
     *
     * @throws VoucherException
     */
    public function validate(User $user, string $voucherCode, float $orderAmount, int $store_id, ?string $customerType = null): array
    {
        $AgencyID = $user->UserType == 1 ? $user->id : $user->AgencyID;
        $voucher = CustomerVoucher::with('orderItem')
            ->join('vouchers', 'customer_vouchers.voucher_id', '=', 'vouchers.id')
            ->where('customer_vouchers.customer_id', $user->id)->where('vouchers.store_id', $store_id)
            ->where('customer_vouchers.AgencyID', $AgencyID)
            ->where('customer_vouchers.voucher_code', $voucherCode)
            ->first();

        if (! $voucher) {
            throw new VoucherException('Invalid voucher code.');
        }

        $this->assertUsable($voucher, $user, $orderAmount, $customerType);

        $discount = $this->computeDiscount($voucher, $orderAmount);

        return [
            'voucher'  => $voucher,
            'discount' => $discount,
            'payable'  => round(max(0, $orderAmount - $discount), 2),
        ];
    }

    /**
     * Actually consume the voucher. Call this only when the booking is confirmed/paid.
     * Row-locked so two parallel requests can't both redeem the same code.
     *
     * @throws VoucherException
     */
    public function redeem(
        User $user,
        string $voucherCode,
        float $orderAmount,
        string $bookingType,
        string $bookingRef,
        ?string $customerType = null
    ): array {
        return DB::transaction(function () use ($user, $voucherCode, $orderAmount, $bookingType, $bookingRef, $customerType) {
            $voucher = CustomerVoucher::with('orderItem')
                ->where('voucher_code', $voucherCode)
                ->lockForUpdate()
                ->first();

            if (! $voucher) {
                throw new VoucherException('Invalid voucher code.');
            }

            // Re-check inside the lock — state may have changed since validate()
            $this->assertUsable($voucher, $user, $orderAmount, $customerType);

            $discount = $this->computeDiscount($voucher, $orderAmount);

            $voucher->update([
                'status'            => 'used',
                'used_at'           => now(),
                'used_ref'          => $bookingRef,
                'used_booking_type' => $bookingType,
            ]);

            return [
                'voucher'  => $voucher,
                'discount' => $discount,
                'payable'  => round(max(0, $orderAmount - $discount), 2),
            ];
        });
    }

    /**
     * Put a voucher back to active. Use only if a booking that already redeemed
     * it is cancelled/reversed after the fact (e.g. post-payment failure, refund).
     */
    public function release(string $voucherCode): void
    {
        DB::transaction(function () use ($voucherCode) {
            $voucher = CustomerVoucher::where('voucher_code', $voucherCode)->lockForUpdate()->first();

            if (! $voucher || $voucher->status !== 'used') {
                return;
            }

            $voucher->update([
                'status'            => 'active',
                'used_at'           => null,
                'used_ref'          => null,
                'used_booking_type' => null,
            ]);
        });
    }

    /* ------------------------------ internals ------------------------------ */

    private function assertUsable(CustomerVoucher $voucher, User $user, float $orderAmount, ?string $customerType): void
    {
        if ($voucher->customer_id !== $user->id) {
            throw new VoucherException('Invalid voucher code.');
        }

        if ($voucher->status === 'used') {
            throw new VoucherException('This voucher has already been used.');
        }

        if ($voucher->status === 'cancelled') {
            throw new VoucherException('This voucher has been cancelled.');
        }

        if ($voucher->status !== 'active') {
            throw new VoucherException('This voucher is not available for use.');
        }

        $now = Carbon::now();

        if ($voucher->valid_from && $now->lt(Carbon::parse($voucher->valid_from)->startOfDay())) {
            throw new VoucherException('This voucher is not valid yet.');
        }

        if ($voucher->valid_to && $now->gt(Carbon::parse($voucher->valid_to)->endOfDay())) {
            throw new VoucherException('This voucher has expired.');
        }

        $unitPrice = (float) ($voucher->orderItem?->unit_price ?? 0);

        if ($orderAmount < $unitPrice) {
            throw new VoucherException("Order amount must be at least {$unitPrice} to use this voucher.");
        }

        $snap = $voucher->orderItem?->voucher_snapshot ?? [];

        if (! empty($snap['customer_type']) && $customerType !== null && $snap['customer_type'] !== $customerType) {
            throw new VoucherException('This voucher is not valid for your customer type.');
        }
    }
    private function computeDiscount(CustomerVoucher $voucher, float $orderAmount): float
    {
        $unitPrice = (float) ($voucher->orderItem?->unit_price ?? 0);

        // Order must be worth at least the voucher's value to use it
        if ($orderAmount < $unitPrice) {
            throw new VoucherException("Order amount must be at least {$unitPrice} to use this voucher.");
        }

        return round($unitPrice, 2);
    }
    // private function computeDiscount(CustomerVoucher $voucher, float $orderAmount): float
    // {
    //     $snap = $voucher->orderItem?->voucher_snapshot ?? [];

    //     $type  = $snap['discount_type'] ?? null;
    //     $value = (float) ($snap['discount_value'] ?? 0);
    //     $max   = isset($snap['max_discount_value']) ? (float) $snap['max_discount_value'] : null;

    //     $discount = match ($type) {
    //         'percentage', 'percent' => $orderAmount * $value / 100,
    //         'flat', 'fixed'         => $value,
    //         default                 => 0.0,
    //     };

    //     if ($max !== null) {
    //         $discount = min($discount, $max);
    //     }

    //     // Never discount more than the order itself
    //     return round(min($discount, $orderAmount), 2);
    // }
}
