<?php

namespace App\Services\WhatsappNotification\Mappers;

/**
 * EXAMPLE — replace the template name and variables with your approved template.
 *
 * Assumed template body:
 * Hello {{1}},

 * Congratulations! Your *{{2}}* membership has been successfully purchased and is now confirmed.

 * Order Reference: *{{3}}*

 * You can now open the GoerOne app to view your membership details and enjoy your benefits.
 *
 * Template name : goerone_membership_purchase
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
        return 'goerone_membership_purchase';
    }

    public static function map(array $data, string $recipientType = 'customer'): array
    {
        return static::body(
            static::param($data['customer_name'] ?? null, 'Customer'),
            static::param($data['membership_name'] ?? null),
            static::param($data['ref'] ?? null, '0'),
        );
    }
}