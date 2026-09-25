<?php

return [
    'currency'             => 'INR',
    'order_expiry_minutes' => 15,
    'max_qty_per_voucher'  => 10,
    'gst_percent'          => (float) env('VOUCHER_GST_PERCENT', 0),
    'gateway'              => env('VOUCHER_PAYMENT_GATEWAY', 'atom'),
    // Frontend page the customer lands on after payment: {url}/{order_no}
    'frontend_result_url'  => env('VOUCHER_RESULT_URL', '/vouchers/orders'),
];
