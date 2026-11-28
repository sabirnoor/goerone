<?php

/*
 * config/notification_master.php
 *
 * One entry per notification type. Placeholders like {amount} are filled from the
 * payload passed to NotifyMasterBellIcon::send(). To add a notification: add an entry
 * here, then call send() where the event happens.
 */
return [

    // Read notifications older than this are deleted by NotifyMasterBellIcon::prune()
    'retention_days' => 90,

    // Payload keys formatted as money (1,234.50) when rendered into title / message
    'money_keys' => ['amount', 'net_amount', 'cashback'],

    'types' => [

        'payment_received' => [
            'title'      => 'Payment received',
            'message'    => '₹{amount} received from {customer} (Ref {ref}). ', //You get ₹{net_amount} after commission and discounts.
            'action_url' => '/store-panel/',
        ],

        'cashback_pending' => [
            'title'      => 'Cashback pending',
            'message'    => '₹{cashback} cashback for {customer} (Ref {ref}) is pending approval.',
            'action_url' => '/store-panel/reward-pending-ledger',
        ],

        'cashback_released' => [
            'title'      => 'Cashback released',
            'message'    => '₹{cashback} cashback for {customer} (Ref {ref}) has been released.',
            'action_url' => '/store-panel/reward-pending-ledger',
        ],

        'cashback_rejected' => [
            'title'      => 'Cashback rejected',
            'message'    => '₹{cashback} cashback for {customer} (Ref {ref}) was rejected. {remarks}',
            'action_url' => '/store-panel/reward-pending-ledger',
        ],
    ],
];