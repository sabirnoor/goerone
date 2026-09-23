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
        'payment_date'
    ];

    protected $casts = [
        'payment_date' => 'datetime'
    ];

    public function settlement()
    {
        return $this->belongsTo(StoreSettlements::class);
    }
}
