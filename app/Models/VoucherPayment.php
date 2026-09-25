<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherPayment extends Model
{
    protected $fillable = [
        'voucher_order_id',
        'gateway',
        'merchant_txn_id',
        'gateway_txn_id',
        'amount',
        'status',
        'request_payload',
        'response_payload',
        'paid_at',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'paid_at'          => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(VoucherOrder::class, 'voucher_order_id');
    }
}
