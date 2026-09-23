<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDetails extends Model
{
    use HasFactory;
    protected $table = 'products_details';
    protected $fillable = [
        'products_id',
        'title',
        'order_by',
        'description',
    ];

    protected $dates = ['created_at', 'updated_at'];
}
