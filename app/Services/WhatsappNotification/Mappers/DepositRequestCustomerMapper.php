<?php

namespace App\Services\WhatsappNotification\Mappers;

use App\Services\WhatsappNotification\Contracts\WhatsappVarMapper;

/**
 *
 * Template body:
 *   Your deposit request for amount Rs {{1}} has been approved.
 *
 * Example:
 *   Your deposit request for amount Rs 4500 has been approved.
 *
 * Template name : deposit_request_customer
 * Language      : en
 * Header        : Deposit Request
 */
class DepositRequestCustomerMapper implements WhatsappVarMapper
{
    public static function templateName(): string
    {
        return 'deposit_request_customer';
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
        return 'Deposit Request';
    }

    /**
     * @param  array  $bookingDetails  Raw request data. Expected keys:
     *                                  amount
     */
    public static function map(array $bookingDetails, string $recipientType = 'customer'): array
    {

        return [
            'body_1' => $bookingDetails['amount'] ?? 'N/A'
        ];
    }
}
