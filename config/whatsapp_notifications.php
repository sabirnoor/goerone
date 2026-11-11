<?php


use App\Services\WhatsappNotification\Mappers\MembershipPurchasedCustomerMapper;
use App\Services\WhatsappNotification\Mappers\StorePaymentCustomerMapper;
use App\Services\WhatsappNotification\Mappers\StorePaymentPartnerMapper;
use App\Services\WhatsappNotification\Mappers\WithdrawalRequestCustomerMapper;
use App\Services\WhatsappNotification\Mappers\WithdrawalRequestStaffMapper;

return [

    // Verbose step-by-step logging (mapped vars, payload, API response).
    // Payloads contain bank details / phone numbers — keep this OFF in production.
    'debug' => env('WA_NOTIFY_DEBUG', false),

    // Optional dedicated log channel from config/logging.php (null = default channel).
    'log_channel' => env('WA_NOTIFY_LOG_CHANNEL'),

    // When set, EVERY message is redirected to this number. Great for testing.
    'test_number' => env('WA_NOTIFY_TEST_NUMBER'),

    // Fallback staff number when $data['staff_phone'] is not provided.
    'staff_phone' => env('WA_NOTIFY_STAFF_PHONE'),

    /*
     * One entry per business event. Adding a new notification =
     *   1. create the mapper(s)   2. add an entry here   3. call notify().
     *
     * 'recipients' => type (customer|staff) => mapper class
     * 'source'     => value stored in whatsapp_notification_logs.source_type
     */
    'events' => [

        'withdrawal_request' => [
            'source'     => 'withdrawal_request',
            'recipients' => [
                'customer' => WithdrawalRequestCustomerMapper::class,
                'staff'    => WithdrawalRequestStaffMapper::class,
            ],
        ],

        // Example — replace with your real template(s)
        'membership_purchased' => [
            'source'     => 'membership',
            'recipients' => [
                'customer' => MembershipPurchasedCustomerMapper::class,
            ],
        ],
        'goerone_store_payment_partner' => [
            'source'     => 'goerone_store_payment_partner',
            'recipients' => [
                'store' => StorePaymentPartnerMapper::class,
            ],
        ],
        'goerone_store_payment_customer' => [
            'source'     => 'goerone_store_payment_customer',
            'recipients' => [
                'customer' => StorePaymentCustomerMapper::class,
            ],
        ],
    ],
];