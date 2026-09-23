<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreSettlements extends Model
{
    use HasFactory;

    protected $table = 'store_settlement';
    protected $fillable = [
        'store_id',
        'AgencyID',
        'customer_id',
        'settlement_no',
        'from_date',
        'to_date',
        'total_sales',
        'total_discount',
        'payable_amount',
        'redem_count',
        'status',
        'settled_by',
        'settled_at'
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'settled_at' => 'datetime'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function items()
    {
        return $this->hasMany(StoreSettlementItem::class, 'settlement_id');
    }

    public function payments()
    {
        return $this->hasMany(StoreSettlementPayment::class, 'settlement_id');
    }
}
