<?php

namespace App\Services\WhatsappNotification\Mappers;

use App\Services\WhatsappNotification\Contracts\WhatsappVarMapper;

/**
 * Staff-facing mapper for the approved group request alert template.
 *
 * Template body:
 *   New withdrawal request from customer {{1}} for amount
 *    Rs {{2}}. 
 *    Bank details:
 *   Account name  	:  {{3}}
 *   IFSC code	 	: {{4}}
 *   Account number 	: {{5}}
 *   Thank you.
 *
 * Example:
 *   New withdrawal request from customer John Doe for amount
 *    Rs 2200. 
 *    Bank details:
 *    Account name  	:  John Doe
 *    IFSC code	 	: IFSC0001111
 *    Account number 	: 123456789012
 *    Thank you.
 *
 * Template name : withdrawal_request_staff
 * Language      : en
 * Header        : Withdrawal Request
 */
class WithdrawalRequestStaffMapper implements WhatsappVarMapper
{
    public static function templateName(): string
    {
        return 'withdrawal_request_staff';
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
     *                                  customer_name,amount,Account_Name,IFSC_Code
     *                                  Account_No
     */
    public static function map(array $bookingDetails, string $recipientType = 'staff'): array
    {

        return [
            'body_1' => $bookingDetails['customer_name'] ?? 'N/A',
            'body_2' => $bookingDetails['amount'] ?? '0',
            'body_3' => $bookingDetails['Account_Name'] ?? 'N/A',
            'body_4' => $bookingDetails['IFSC_Code'] ?? 'N/A',
            'body_5' => $bookingDetails['Account_No'] ?? 'N/A',
        ];
    }
}
