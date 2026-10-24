<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePayment extends Model
{
    protected $fillable = [
        'store_id',
        'AgencyID',
        'UserSysId',
        'customer_id',
        'reference',
        'amount',
        'currency',
        'status',
        'gateway_txn_id',
        'payment_method',
        'gateway_response',
        'qr_generated_at',
        'expires_at',
        'initiated_at',
        'paid_at',
    ];

    protected $casts = [
        'gateway_response' => 'array',
        'qr_generated_at'  => 'datetime',
        'expires_at'       => 'datetime',
        'initiated_at'     => 'datetime',
        'paid_at'          => 'datetime',
    ];

    public function isFinal(): bool
    {
        return in_array($this->status, ['success', 'failed', 'expired']);
    }
}
