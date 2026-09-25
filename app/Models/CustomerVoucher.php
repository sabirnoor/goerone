<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CustomerVoucher extends Model
{
    protected $fillable = [
        'voucher_code',
        'voucher_order_id',
        'voucher_order_item_id',
        'customer_id',
        'voucher_id',
        'AgencyID',
        'valid_from',
        'valid_to',
        'status',
        'used_at',
        'used_ref',
    ];

    protected $casts = ['used_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(VoucherOrder::class, 'voucher_order_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(VoucherOrderItem::class, 'voucher_order_item_id');
    }

    /** 'active' becomes 'expired' automatically once valid_to has passed */
    public function getEffectiveStatusAttribute(): string
    {
        if (
            $this->status === 'active'
            && $this->valid_to
            && Carbon::parse($this->valid_to)->endOfDay()->isPast()
        ) {
            return 'expired';
        }

        return $this->status;
    }
}
