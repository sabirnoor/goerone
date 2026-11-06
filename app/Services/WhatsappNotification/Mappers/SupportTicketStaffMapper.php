<?php

namespace App\Services\WhatsappNotification\Mappers;

use App\Services\WhatsappNotification\Contracts\WhatsappVarMapper;

/**
 *
 * Template body:
 *  New Deposit request from customer {{1}} for amount of Rs {{2}}. Kindly verify it and approve the deposit request.
 *
 * Example:
 *  New Deposit request from customer John Doe for amount of Rs 4300. Kindly verify it and approve the deposit request.
 *
 * Template name : support_ticket_staff
 * Language      : en
 * Header        : ''
 */
class SupportTicketStaffMapper implements WhatsappVarMapper
{
    public static function templateName(): string
    {
        return 'support_ticket_staff';
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
        return '';
    }

    /**
     * @param  array  $bookingDetails  Raw request data. Expected keys:
     *                                  ticket_number
     */
    public static function map(array $bookingDetails, string $recipientType = 'staff'): array
    {

        return [
            'body_1' => $bookingDetails['ticket_number'] ?? 'N/A'
        ];
    }
}
