<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

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

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id')->without('details', 'agency', 'symbal');
    }
    public function order()
    {
        return $this->belongsTo(VoucherOrder::class, 'voucher_order_id');
    }
    public function voucher()
    {
        return $this->belongsTo(Vouchers::class, 'voucher_id')->with('store:id,store_name,address,phone');
    }
    public function orderItem()
    {
        return $this->belongsTo(VoucherOrderItem::class, 'voucher_order_item_id')->with('voucher');
    }
    /** Codes this customer can still redeem right now */
    public function scopeRedeemable(Builder $q, int $customerId): Builder
    {
        return $q->where('customer_id', $customerId)
            ->where('status', 'active')
            ->where(fn($w) => $w->whereNull('valid_from')->orWhereDate('valid_from', '<=', today()))
            ->where(fn($w) => $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', today()));
    }

    public function isExpired(): bool
    {
        return $this->valid_to && Carbon::parse($this->valid_to)->endOfDay()->isPast();
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
