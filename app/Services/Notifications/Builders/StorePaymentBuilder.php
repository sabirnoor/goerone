<?php

namespace App\Services\Notifications\Builders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/** ctx: customer_id, store_id, amount, ref, payment_at */
class StorePaymentBuilder implements NotificationBuilder
{
    public static function build(array $ctx): array
    {
        $customer = User::find($ctx['customer_id'] ?? 0);
        $store    = Store::find($ctx['store_id'] ?? 0);

        if (!$customer || !$store) {
            Log::warning('[StorePaymentBuilder] customer/store not found', $ctx);
            return [];
        }

        $formatted = 'INR ' . number_format((float) ($ctx['amount'] ?? 0), 2);
        $ref       = (string) ($ctx['ref'] ?? '');

        $data = [
            'customer_name'  => $customer->name ?? 'Customer',
            'store_name'     => $store->store_name,
            'payment_amount' => $formatted,
            'payment_ref'    => $ref,
            'payment_at'     => $ctx['payment_at'] ?? null,
            'amount'         => $formatted,   // WhatsApp mappers
            'ref'            => $ref,
        ];

        $sender = $store->AgencyID ? User::find($store->AgencyID) : null;

        $out = [
            'customer' => [
                'user'  => $customer,
                'email' => $customer->email,
                'phone' => $customer->mobile,
                'data'  => $data,
            ],
        ];

        if ($sender) {
            $out['store'] = [
                'user'  => $sender,
                'email' => $store->email ?: $sender->email,
                'phone' => $store->phone ?: $sender->phone,
                'data'  => $data,
            ];
        } else {
            Log::warning('[StorePaymentBuilder] no agency user for store', ['store_id' => $store->id]);
        }

        return $out;
    }
}