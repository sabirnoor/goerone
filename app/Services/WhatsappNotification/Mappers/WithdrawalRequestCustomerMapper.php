<?php

namespace App\Services\WhatsappNotification\Mappers;

use App\Services\WhatsappNotification\Contracts\WhatsappVarMapper;

/**
 *
 * Template body:
 *   Your wallet-to-bank transfer request for the amount of {{1}} has been successfully processed. 
 *    The funds have been transferred to your bank account ending in {{2}}. *Transaction ID*: {{3}}. 
 *    Thank you.
 *
 * Example:
 *   Your wallet-to-bank transfer request for the amount of 13000 has been successfully processed. 
 *    The funds have been transferred to your bank account ending in 6756. Transaction ID: *    TXN-20260512-0001. 
 *    Thank you.
 *
 * Template name : withdrawal_request_customer
 * Language      : en
 * Header        : Withdrawal Request
 */
class WithdrawalRequestCustomerMapper implements WhatsappVarMapper
{
    public static function templateName(): string
    {
        return 'withdrawal_request_customer';
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
        return 'Withdrawal Request';
    }

    /**
     * @param  array  $bookingDetails  Raw request data. Expected keys:
     *                                  amount,account_ending,transaction_id
     */
    public static function map(array $bookingDetails, string $recipientType = 'customer'): array
    {

        return [
            'body_1' => $bookingDetails['amount'] ?? 'N/A',
            'body_2' => $bookingDetails['account_ending'] ?? '0000',
            'body_3' => $bookingDetails['transaction_id'] ?? 'N/A',
        ];
    }
}
