<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSettlementPayment extends Model
{
    protected $fillable = [
        'AgencyID',
        'UserSysId',
        'settlement_id',
        'amount',
        'payment_mode',
        'reference_no',
        'remarks',
        'payment_date',
        'direction',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(StoreSettlements::class, 'settlement_id');
    }
}