<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSettlementItem extends Model
{
    protected $fillable = [
        'settlement_id',
        'redemption_id',   // kept for backward compatibility; same value as source_id for redemptions
        'amount',          // signed: + payable to store, - receivable from store
        'item_type',
        'source_id',
        'entry_kind',
        'side',
        'is_cash',
        'order_amount',
        'store_discount',
        'goerone_discount',
        'commission',
        'pg_fee',
        'eligible_on',
        'ref_no',
        'txn_date',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'order_amount' => 'decimal:2',
        'store_discount' => 'decimal:2',
        'goerone_discount' => 'decimal:2',
        'commission' => 'decimal:2',
        'pg_fee' => 'decimal:2',
        'eligible_on' => 'date',
        'txn_date' => 'datetime',
    ];

    public function settlement()
    {
        return $this->belongsTo(StoreSettlements::class, 'settlement_id');
    }

    // Only meaningful when item_type = 'redemption' (for voucher items source_id is a ledger id)
    public function redemption()
    {
        return $this->belongsTo(LoyaltyRedemption::class, 'source_id', 'redemption_id');
    }
}