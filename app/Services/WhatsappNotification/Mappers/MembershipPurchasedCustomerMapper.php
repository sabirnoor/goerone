<?php

namespace App\Services\WhatsappNotification\Mappers;

/**
 * EXAMPLE — replace the template name and variables with your approved template.
 *
 * Assumed template body:
 *   Hi {{1}}, your {{2}} membership has been activated for Rs {{3}}.
 *   It is valid till {{4}}. Thank you for choosing GoerOne.
 *
 * Template name : membership_purchased_customer
 * Language      : en
 * Header        : none
 *
 * Expected keys in $data: customer_name, membership_name, amount, valid_till
 * (plus customer_phone, which the service uses to find the recipient)
 */
class MembershipPurchasedCustomerMapper extends BaseWhatsappMapper
{
    public static function templateName(): string
    {
        return 'membership_purchased_customer';
    }

    public static function map(array $data, string $recipientType = 'customer'): array
    {
        return static::body(
            static::param($data['customer_name'] ?? null, 'Customer'),
            static::param($data['membership_name'] ?? null),
            static::param($data['amount'] ?? null, '0'),
            static::param($data['valid_till'] ?? null),
        );
    }
}