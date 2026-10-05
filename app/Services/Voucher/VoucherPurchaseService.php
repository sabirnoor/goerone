<?php

namespace App\Services\Voucher;

use App\Exceptions\VoucherException;
use App\Models\CustomerVoucher;
use App\Models\User;
use App\Models\VoucherCartItem;
use App\Models\VoucherOrder;
use App\Models\VoucherPayment;
use App\Models\Vouchers;
use App\Services\Voucher\Gateways\VoucherPaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class VoucherPurchaseService
{
    public function __construct(private VoucherPaymentGateway $gateway) {}

    /* ------------------------------------------------------------------
     |  CHECKOUT: cart -> pending order (stock reserved) -> start payment
     * ------------------------------------------------------------------ */
    public function checkout(User $user): array
    {
        [$order, $payment] = DB::transaction(function () use ($user) {
            $AgencyID = $user->UserType == 1 ? $user->id : $user->AgencyID;
            $cartItems = VoucherCartItem::where('customer_id', $user->id)->get();

            if ($cartItems->isEmpty()) {
                throw new VoucherException('Your voucher cart is empty.');
            }

            // Cancel this customer's older unpaid orders so their stock is released
            VoucherOrder::where('customer_id', $user->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get()
                ->each(fn($o) => $this->release($o, 'cancelled'));

            // Lock voucher rows so two buyers can't take the last unit
            $vouchers = Vouchers::where('AgencyID', $AgencyID)->whereIn('id', $cartItems->pluck('voucher_id'))
                ->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0;
            $lines    = [];

            foreach ($cartItems as $ci) {
                $v = $vouchers->get($ci->voucher_id);

                if (! $v || ! $v->isPurchasable()) {
                    throw new VoucherException("'" . ($v->voucher_name ?? 'A voucher') . "' is no longer available.");
                }

                $left = $v->remainingStock();
                if ($left !== null && $ci->quantity > $left) {
                    throw new VoucherException("Only {$left} left for '{$v->voucher_name}'.");
                }

                $line      = round($v->voucher_price * $ci->quantity, 2);
                $subtotal += $line;
                $lines[]   = ['voucher' => $v, 'qty' => $ci->quantity, 'line' => $line];
            }

            $subtotal = round($subtotal, 2);
            $tax      = round($subtotal * config('voucher.gst_percent') / 100, 2);

            $order = VoucherOrder::create([
                'order_no'       => $this->generateOrderNo(),
                'customer_id'    => $user->id,
                'subtotal'       => $subtotal,
                'tax_amount'     => $tax,
                'total_amount'   => round($subtotal + $tax, 2),
                'currency'       => config('voucher.currency'),
                'status'         => 'pending',
                'stock_reserved' => true,
                'expires_at'     => now()->addMinutes(config('voucher.order_expiry_minutes')),
            ]);

            foreach ($lines as $l) {
                /** @var Vouchers $v */
                $v = $l['voucher'];

                $order->items()->create([
                    'voucher_id'       => $v->id,
                    'AgencyID'         => $v->AgencyID,
                    'voucher_name'     => $v->voucher_name,
                    'unit_price'       => $v->voucher_price,
                    'quantity'         => $l['qty'],
                    'line_total'       => $l['line'],
                    'voucher_snapshot' => $v->only([
                        'customer_type',
                        'discount_type',
                        'discount_value',
                        'max_discount_value',
                        'gtcoin_required',
                        'required_value',
                        'valid_from',
                        'valid_to',
                        'terms_condition',
                    ]),
                ]);

                $v->increment('sold_count', $l['qty']); // reserve stock
            }

            $payment = $order->payments()->create([
                'gateway'         => config('voucher.gateway'),
                'merchant_txn_id' => $this->generateTxnId($order),
                'amount'          => $order->total_amount,
                'status'          => 'initiated',
            ]);

            return [$order, $payment];
        });

        // Free vouchers (total = 0): no gateway needed, fulfil straight away
        if ((float) $order->total_amount <= 0) {
            $order = $this->handleGatewayResult([
                'merchant_txn_id' => $payment->merchant_txn_id,
                'gateway_txn_id'  => 'FREE',
                'status'          => 'success',
                'amount'          => 0,
                'raw'             => ['free_order' => true],
            ]);

            return ['order' => $order->load('items', 'customerVouchers.orderItem'), 'payment_required' => false, 'payment' => null];
        }

        try {
            $paymentData = $this->gateway->initiate($order, $payment, $user);
        } catch (Throwable $e) {
            Log::error('Voucher payment initiate failed', ['order' => $order->order_no, 'error' => $e->getMessage()]);

            DB::transaction(function () use ($order) {
                $locked = VoucherOrder::lockForUpdate()->find($order->id);
                if ($locked && $locked->status === 'pending') {
                    $this->release($locked, 'failed');
                }
            });

            throw new VoucherException('Unable to start payment. Please try again.');
        }

        return ['order' => $order->load('items'), 'payment_required' => true, 'payment' => $paymentData];
    }

    /* ------------------------------------------------------------------
     |  GATEWAY RESULT (callback + webhook both land here; idempotent)
     * ------------------------------------------------------------------ */
    public function handleGatewayResult(array $r): VoucherOrder
    {
        return DB::transaction(function () use ($r) {
            $payment = VoucherPayment::where('merchant_txn_id', $r['merchant_txn_id'])->lockForUpdate()->firstOrFail();
            $order   = VoucherOrder::with('items')->lockForUpdate()->findOrFail($payment->voucher_order_id);

            // Callback and webhook can both arrive - second one is a no-op
            if ($order->status === 'paid') {
                return $order;
            }

            $payment->fill([
                'gateway_txn_id'   => $r['gateway_txn_id'] ?? null,
                'response_payload' => $r['raw'] ?? null,
            ]);

            if ($r['status'] === 'pending') {
                $payment->save();
                return $order;
            }

            if ($r['status'] !== 'success') {
                $payment->status = 'failed';
                $payment->save();
                $this->release($order, 'failed');
                return $order;
            }

            // ---- payment succeeded ----
            $payment->status  = 'success';
            $payment->paid_at = now();
            $payment->save();

            // Paid amount must match the order (guards against tampered amounts)
            if (abs((float) $r['amount'] - (float) $order->total_amount) > 0.01) {
                Log::critical('Voucher payment amount mismatch', [
                    'order' => $order->order_no,
                    'expected' => $order->total_amount,
                    'received' => $r['amount'],
                ]);
                $this->release($order, 'refund_pending');
                return $order;
            }

            // Order may have expired before the customer finished paying: try to re-reserve stock
            if (! $order->stock_reserved && ! $this->reserveStock($order)) {
                Log::warning('Voucher paid after expiry and stock gone', ['order' => $order->order_no]);
                $order->update(['status' => 'refund_pending']);
                return $order;
            }

            $order->update(['status' => 'paid', 'paid_at' => now(), 'stock_reserved' => true]);

            $this->issueVouchers($order);

            VoucherCartItem::where('customer_id', $order->customer_id)
                ->whereIn('voucher_id', $order->items->pluck('voucher_id'))
                ->delete();

            return $order;
        });
    }

    /* ------------------------------------------------------------------
     |  EXPIRY (called by scheduler)
     * ------------------------------------------------------------------ */
    public function expirePending(): int
    {
        $count = 0;

        VoucherOrder::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->pluck('id')
            ->each(function ($id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $o = VoucherOrder::lockForUpdate()->find($id);
                    if ($o && $o->status === 'pending' && $o->expires_at->isPast()) {
                        $this->release($o, 'expired');
                        $count++;
                    }
                });
            });

        return $count;
    }

    /* ------------------------------ helpers ------------------------------ */

    private function issueVouchers(VoucherOrder $order): void
    {
        foreach ($order->items as $item) {
            $snap = $item->voucher_snapshot ?? [];

            for ($i = 0; $i < $item->quantity; $i++) {
                CustomerVoucher::create([
                    'voucher_code'          => $this->generateVoucherCode(),
                    'voucher_order_id'      => $order->id,
                    'voucher_order_item_id' => $item->id,
                    'customer_id'           => $order->customer_id,
                    'voucher_id'            => $item->voucher_id,
                    'AgencyID'              => $item->AgencyID,
                    'valid_from'            => $snap['valid_from'] ?? null,
                    'valid_to'              => $snap['valid_to'] ?? null,
                    'status'                => 'active',
                ]);
            }
        }
    }

    private function reserveStock(VoucherOrder $order): bool
    {
        $vouchers = Vouchers::whereIn('id', $order->items->pluck('voucher_id'))
            ->lockForUpdate()->get()->keyBy('id');

        foreach ($order->items as $item) {
            $v    = $vouchers->get($item->voucher_id);
            $left = $v?->remainingStock();

            if (! $v || ($left !== null && $item->quantity > $left)) {
                return false;
            }
        }

        foreach ($order->items as $item) {
            $vouchers[$item->voucher_id]->increment('sold_count', $item->quantity);
        }

        $order->stock_reserved = true;

        return true;
    }

    private function release(VoucherOrder $order, string $newStatus): void
    {
        if ($order->stock_reserved) {
            $order->loadMissing('items');

            foreach ($order->items as $item) {
                Vouchers::whereKey($item->voucher_id)
                    ->where('sold_count', '>=', $item->quantity)
                    ->decrement('sold_count', $item->quantity);
            }
        }

        $order->update(['status' => $newStatus, 'stock_reserved' => false]);
    }

    private function generateOrderNo(): string
    {
        do {
            $no = 'VO' . now()->format('ymd') . strtoupper(Str::random(6));
        } while (VoucherOrder::where('order_no', $no)->exists());

        return $no;
    }

    private function generateTxnId(VoucherOrder $order): string
    {
        return 'VP' . $order->id . strtoupper(Str::random(8));
    }

    private function generateVoucherCode(): string
    {
        do {
            $code = 'GV' . strtoupper(Str::random(10));
        } while (CustomerVoucher::where('voucher_code', $code)->exists());

        return $code;
    }
}
