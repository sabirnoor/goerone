<?php

namespace App\Services\Notifications\Builders;

interface NotificationBuilder
{
    /**
     * Turn the (scalar) context into recipients.
     *
     * @return array<string, array{
     *   user: \App\Models\User,      // account used to pick agency/WhatsApp account/mail settings
     *   email: ?string,
     *   phone: ?string,
     *   data: array,                 // shared by mail view + WhatsApp mapper
     *   attachments?: array          // [['path'=>..., 'name'=>..., 'temp'=>true]]
     * }>  keyed by recipient type (customer|store|staff)
     */
    public static function build(array $ctx): array;
}