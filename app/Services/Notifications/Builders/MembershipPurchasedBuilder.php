<?php

namespace App\Services\Notifications\Builders;

use App\Models\User;
use App\Services\Notifications\InvoicePdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/** ctx: customer_id, invoice_id, membership_name, amount, valid_till, ref */
class MembershipPurchasedBuilder implements NotificationBuilder
{
    public static function build(array $ctx): array
    {
        $customer = User::find($ctx['customer_id'] ?? 0);

        if (!$customer) {
            Log::warning('[MembershipPurchasedBuilder] customer not found', $ctx);
            return [];
        }

        $validTill = !empty($ctx['valid_till']) ? Carbon::parse($ctx['valid_till'])->format('d M Y') : 'N/A';

        $data = [
            'customer_name'   => $customer->name ?? 'Customer',
            'membership_name' => $ctx['membership_name'] ?? 'Membership',
            'amount'          => number_format((float) ($ctx['amount'] ?? 0), 2),
            'valid_till'      => $validTill,
            'ref'             => (string) ($ctx['ref'] ?? ''),
        ];

        $attachments = [];
        if (!empty($ctx['invoice_id'])) {
            try {
                $pdf = InvoicePdf::make((int) $ctx['invoice_id']);   // generated here = in background
                $attachments[] = ['path' => $pdf['path'], 'name' => $pdf['name'], 'temp' => true];
            } catch (\Throwable $e) {
                // mail still goes out, just without the PDF
                Log::error('[MembershipPurchasedBuilder] invoice pdf failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'customer' => [
                'user'        => $customer,
                'email'       => $customer->email,
                'phone'       => $customer->mobile,
                'data'        => $data,
                'attachments' => $attachments,
            ],
        ];
    }
}