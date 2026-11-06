<?php

namespace App\Services\WhatsappNotification\Mappers;

use App\Services\WhatsappNotification\Contracts\WhatsappVarMapper;

/**
 * Mapper for the failed/error booking notification template.
 *
 * Template body (example):
 * Dear John,
 * Dear John,
 * Your booking with PNR JHBGFR has been successfully processed for refund.
 * Refund amount is ₹4,731.00
 * Thank you.
 *
 * Header: Refund
 */
class RefundCustomerMapper implements WhatsappVarMapper
{
    public static function templateName(): string
    {
        return 'refund_customer';
    }
    public static function langCode(): string
    {
        return 'en';
    }
    public static function headerFormat(): ?string
    {
        return 'TEXT';
    }
    public static function needsPdfHeader(): bool
    {
        return false;
    }
    public static function staticHeaderValue(): ?string
    {
        return 'Refund';
    }

    public static function map(array $bookingDetails, string $recipientType = 'customer'): array
    {
        $customer  = $bookingDetails['customer'][0]      ?? [];
        $booking   = $bookingDetails['BookingDetails'][0] ?? [];

        $fname     = ucfirst(trim($customer['fname'] ?? 'Customer'));
        $pnr = $booking['PNR'] ?? '';
        $price = $booking['price'] ?? '';

        if ($recipientType === 'staff') {
            $fname = 'Team';
        }

        return [
            'body_1' => $fname,
            'body_2' => $pnr,
            'body_3' => $price,
        ];
    }
}
