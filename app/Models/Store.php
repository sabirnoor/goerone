<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Store extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'store_name',
        'email',
        'password',
        'address',
        'phone',
        'store_timings',
        'product_types',
        'enquiry_enabled',
        'payment_enabled',
        'is_halal',
        'food_type',
        'alcohol_allowed',
        'updated_by_store',
        'platform_fee',
        'platform_fee_payer',
        'qr_handle',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'store_timings' => 'array',
        'product_types' => 'array',
        'enquiry_enabled' => 'boolean',
        'payment_enabled' => 'boolean',
        'is_halal' => 'boolean',
        'alcohol_allowed' => 'boolean',
        'platform_fee' => 'float',
        'is_active' => 'boolean',
    ];

    /** Default permanent QR handle for a store, e.g. store-42@goerone */
    public static function makeQrHandle($storeId): string
    {
        return 'store-' . (int) $storeId . '@goerone';
    }

    public function settlements()
    {
        return $this->hasMany(StoreSettlements::class);
    }

     public function images()
    {
        return $this->hasMany(StoreImage::class)->orderBy('sort_order');
    }
}
