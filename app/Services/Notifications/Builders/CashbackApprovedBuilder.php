<?php

namespace App\Services\Notifications\Builders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/** ctx: customer_id, store_id, amount, ref */
class CashbackApprovedBuilder implements NotificationBuilder
{
    public static function build(array $ctx): array
    {
        $customer = User::without('details', 'agency', 'symbal')->find($ctx['customer_id'] ?? 0);

        if (!$customer) {
            Log::warning('[CashbackApprovedBuilder] customer not found', $ctx);
            return [];
        }

        $store = !empty($ctx['store_id']) ? Store::find($ctx['store_id']) : null;

        $name      = trim(($customer->fname ?? '') . ' ' . ($customer->lname ?? '')) ?: ($customer->name ?? 'Customer');
        $formatted = 'INR ' . number_format((float) ($ctx['amount'] ?? 0), 2);
        $ref       = (string) ($ctx['ref'] ?? '');

        $data = [
            // mail view
            'customer_name'   => $name,
            'store_name'      => $store->store_name ?? 'your store',
            'cashback_amount' => $formatted,
            'cashback_ref'    => $ref,
            // WhatsApp mapper + runner reference
            'amount'          => $formatted,
            'ref'             => $ref,
        ];

        return [
            'customer' => [
                'user'  => $customer,
                'email' => $customer->email,
                'phone' => $customer->mobile,
                'data'  => $data,
            ],
        ];
    }
}