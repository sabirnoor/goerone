<?php

namespace App\Services\WhatsappNotification\Mappers;

 /**
 * EXAMPLE — replace the template name and variables with your approved template.
 *
 * Assumed template body:
 *   GoerOne has confirmed receipt of *{{1}}* for your store *{{2}}*.
 *   Payment reference number: *{{3}}*.
 *   Log in to your Partner Dashboard to view the transaction details.
 *
 * Template name : goerone_store_payment_partner
 * Language      : en
 * Header        : Payment Received
 *
 * Expected keys in $data: store_name, amount, ref
 * (plus customer_phone, which the service uses to find the recipient)
 */
class StorePaymentPartnerMapper extends BaseWhatsappMapper
{
    public static function templateName(): string
    {
        return 'goerone_store_payment_partner';
    }

    public static function map(array $data, string $recipientType = 'customer'): array
    {
        return static::body(
            static::param($data['amount'] ?? null, '0'),
            static::param($data['store_name'] ?? null),
            static::param($data['ref'] ?? null),
        );
    }
}