<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per paid voucher order item = the historical settlement snapshot of that purchase.
 * Later changes to the voucher (price, discount, commission) never change these rows.
 */
class VoucherPurchaseLedger extends Model
{
    protected $table = 'voucher_purchase_ledger';

    protected $fillable = [
        'AgencyID',
        'store_id',
        'voucher_id',
        'voucher_order_id',
        'voucher_order_item_id',
        'order_no',
        'voucher_name',
        'customer_id',
        'quantity',
        'face_value',
        'customer_discount',
        'customer_paid',
        'discount_source',
        'store_discount',
        'admin_discount',
        'commission_type',
        'commission_value',
        'commission_amount',
        'pg_fee',
        'store_payable',
        'paid_at',
        'status',          // posted | settled | reversed
        'settlement_id',
        'settled_at',
    ];

    protected $casts = [
        'face_value' => 'decimal:2',
        'customer_discount' => 'decimal:2',
        'customer_paid' => 'decimal:2',
        'store_discount' => 'decimal:2',
        'admin_discount' => 'decimal:2',
        'commission_value' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'pg_fee' => 'decimal:2',
        'store_payable' => 'decimal:2',
        'paid_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(VoucherOrder::class, 'voucher_order_id');
    }
}