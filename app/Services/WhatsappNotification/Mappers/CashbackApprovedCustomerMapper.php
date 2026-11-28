<?php

namespace App\Services\WhatsappNotification\Mappers;

/**
 * Template name : cashback_approved_customer
 * Language      : en
 *
 * Body:
 * Hi {{1}},
 *
 * Great news! Your cashback of {{2}} has been successfully approved for your purchase at {{3}}.
 *
 * Your cashback reference ID is {{4}}.
 *
 * Open the GoerOne app to view your cashback details and track its status.
 *
 * Expected keys in $data: customer_name, amount, store_name, ref
 * (plus customer_phone, which the service uses to find the recipient)
 */
class CashbackApprovedCustomerMapper extends BaseWhatsappMapper
{
    public static function templateName(): string
    {
        return 'cashback_approved_customer';
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