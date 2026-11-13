<?php

namespace App\Services\Notifications;

use App\Jobs\SendGoerOneNotification;
// use Illuminate\Support\Facades\Log;

/**
 * The single entry point for every mail + WhatsApp notification.
 *
 *   GoerOneNotifier::send('store_payment', [...ids and scalars only...]);
 *   GoerOneNotifier::send('store_payment', $ctx, only: ['customer']);
 *
 * Returns immediately; nothing here waits for SMTP or the WhatsApp API.
 */
class GoerOneNotifier
{
    public static function send(string $event, array $context, ?array $only = null): void
    {
        
        if (config('goerone_notifications.mode') === 'queue') {
           SendGoerOneNotification::dispatch($event, $context, $only)->onQueue('goerone_notifications');
           return;
        }

        // Runs after the response has been sent to the client.
        SendGoerOneNotification::dispatchAfterResponse($event, $context, $only);
    }
}