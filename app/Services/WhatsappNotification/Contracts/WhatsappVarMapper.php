<?php

namespace App\Services\WhatsappNotification\Contracts;

interface WhatsappVarMapper
{
    /**
     * The WhatsApp template name this mapper handles.
     */
    public static function templateName(): string;

    /**
     * Language code for this template (e.g. 'en', 'en_US').
     */
    public static function langCode(): string;

    /**
     * Header format: 'IMAGE' | 'DOCUMENT' | 'VIDEO' | 'TEXT' | null
     */
    public static function headerFormat(): ?string;

    /**
     * Whether this template needs a dynamically generated PDF ticket as header.
     * When true, FlightNotificationService will generate the PDF and pass its URL.
     */
    public static function needsPdfHeader(): bool;

    /**
     * Static header URL (for IMAGE/VIDEO headers with a fixed asset).
     * Return null when the header is dynamic (PDF) or not needed.
     */
    public static function staticHeaderValue(): ?string;

    /**
     * Map booking details array → var_values array (body_1, body_2, btn_0_1 …).
     *
     * @param  array  $bookingDetails   Full array from FlightBookingModel::BookingDetails()
     * @param  string $recipientType    'customer' | 'staff' — lets mapper vary content per recipient
     */
    public static function map(array $bookingDetails, string $recipientType = 'customer'): array;
}
