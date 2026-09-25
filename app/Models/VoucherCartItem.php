<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherCartItem extends Model
{
    protected $fillable = ['customer_id', 'voucher_id', 'quantity'];

    public function voucher()
    {
        return $this->belongsTo(Vouchers::class, 'voucher_id');
    }
}
