<?php

namespace App\Services\WhatsappNotification\Mappers;

/**
 * EXAMPLE — replace the template name and variables with your approved template.
 *
 * Assumed template body:
 * Hi {{1}}, your GoerOne payment of *{{2}}* to *{{3}}* is confirmed. Your payment reference is *{{4}}*. Open GoerOne to view the transaction.
 *
 * Template name : goerone_store_payment_customer
 * Language      : en
 * Header        : Payment Successful
 *
 * Expected keys in $data: customer_name, store_name, amount, ref
 * (plus customer_phone, which the service uses to find the recipient)
 */
class StorePaymentCustomerMapper extends BaseWhatsappMapper
{
    public static function templateName(): string
    {
        return 'goerone_store_payment_customer';
    }

    public static function map(array $data, string $recipientType = 'customer'): array
    {
        return static::body(
            static::param($data['customer_name'] ?? null, 'There'),
            static::param($data['amount'] ?? null, '0'),
            static::param($data['store_name'] ?? null),
            static::param($data['ref'] ?? null),
        );
    }
}