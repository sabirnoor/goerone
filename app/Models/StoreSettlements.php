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
        'total_discount',      // v2: total redemption commission
        'payable_amount',      // v2: ABS(net_amount) = amount to be settled
        'redem_count',
        'status',              // pending | partial | paid
        'settled_by',
        'settled_at',
        // v2
        'calc_version',        // 1 legacy, 2 new
        'direction',           // store_receives | store_pays
        'payable_to_store',
        'receivable_from_store',
        'net_amount',          // + GoerOne owes store, - store owes GoerOne
        'paid_amount',
        'total_pg_fee',
        'total_store_discount',
        'total_goerone_discount',
        'run_date',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'run_date' => 'date',
        'settled_at' => 'datetime',
        'payable_amount' => 'decimal:2',
        'payable_to_store' => 'decimal:2',
        'receivable_from_store' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
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