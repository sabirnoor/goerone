<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Products_PickupAddress extends Model
{
    use HasFactory;

    protected $table = 'products_pickup_address';

    protected $fillable = [
        'products_id',
        'title',
        'address',
        'is_primary',
        'longitude',
        'latitude'

    ];

    protected $dates = ['created_at', 'updated_at'];
}
