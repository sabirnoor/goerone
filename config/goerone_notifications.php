<?php

use App\Services\Notifications\Builders\MembershipPurchasedBuilder;
use App\Services\Notifications\Builders\StorePaymentBuilder;

/*
 * ONE place to describe every mail + WhatsApp notification.
 *
 * Adding a new notification =
 *   1. Builder class  (context -> recipients + data)
 *   2. entry below    (which mail view / WhatsApp event per recipient)
 *   3. GoerOneNotifier::send('event', [...ids/scalars...]);
 *
 * 'whatsapp' = an event key from config/whatsapp_notifications.php
 * 'mail'     = view + subject ({placeholders} are filled from the builder's data)
 */
return [

    // 'after_response' : runs right after the HTTP response is sent (no queue worker needed)
    // 'queue'          : pushes a job on the queue (needs `php artisan queue:work`)
    'mode' => env('GO_NOTIFY_MODE', 'queue'),

    'events' => [

        'store_payment' => [
            'builder'    => StorePaymentBuilder::class,
            'recipients' => [
                'customer' => [
                    'mail'     => ['view' => 'emails.store_payment_customer', 'subject' => 'Payment Successful - GoerOne'],
                    'whatsapp' => 'goerone_store_payment_customer',
                ],
                'store' => [
                    'mail'     => ['view' => 'emails.store_payment_merchant', 'subject' => 'Payment Received - {store_name}'],
                    'whatsapp' => 'goerone_store_payment_partner',
                ],
            ],
        ],

        'membership_purchased' => [
            'builder'    => MembershipPurchasedBuilder::class,
            'recipients' => [
                'customer' => [
                    'mail'     => ['view' => 'emails.membership_purchased_customer', 'subject' => 'Your {membership_name} membership is active - GoerOne'],
                    'whatsapp' => 'membership_purchased',
                ],
            ],
        ],
    ],
];