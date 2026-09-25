<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherOrderItem extends Model
{
    protected $fillable = [
        'voucher_order_id',
        'voucher_id',
        'AgencyID',
        'voucher_name',
        'unit_price',
        'quantity',
        'line_total',
        'voucher_snapshot',
    ];

    protected $casts = [
        'unit_price'       => 'decimal:2',
        'line_total'       => 'decimal:2',
        'voucher_snapshot' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(VoucherOrder::class, 'voucher_order_id');
    }
}
