<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSettlementItem extends Model
{
    protected $fillable = [
        'settlement_id',
        'redemption_id',
        'amount'
    ];

    public function settlement()
    {
        return $this->belongsTo(StoreSettlements::class);
    }

    public function redemption()
    {
        return $this->belongsTo(LoyaltyRedemption::class, 'redemption_id', 'redemption_id');
    }
}
