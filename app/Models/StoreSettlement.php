<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreSettlement extends Model
{
    use HasFactory;

    protected $table = 'store_settlements';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'AgencyID',
        'payee_id',
        'total_points',
        'commission',
        'net_point',
        'settlement_date',
        'settled',
        'status',
    ];

    protected $casts = [
        'total_points' => 'decimal:2',
        'commission' => 'decimal:2',
        'net_point' => 'decimal:2',
        'settlement_date' => 'datetime',
        'processed_at' => 'datetime'
    ];

    /**
     * Relationships
     */

    public function payee()
    {
        return $this->belongsTo(User::class, 'payee_id');
    }
    // public function store()
    // {
    //     return $this->belongsTo(Store::class, 'store_id');
    // }
}
