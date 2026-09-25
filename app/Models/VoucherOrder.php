<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherOrder extends Model
{
    protected $fillable = [
        'order_no',
        'customer_id',
        'subtotal',
        'tax_amount',
        'total_amount',
        'currency',
        'status',
        'stock_reserved',
        'expires_at',
        'paid_at',
    ];

    protected $casts = [
        'subtotal'       => 'decimal:2',
        'tax_amount'     => 'decimal:2',
        'total_amount'   => 'decimal:2',
        'stock_reserved' => 'boolean',
        'expires_at'     => 'datetime',
        'paid_at'        => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(VoucherOrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(VoucherPayment::class);
    }

    public function customerVouchers()
    {
        return $this->hasMany(CustomerVoucher::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
