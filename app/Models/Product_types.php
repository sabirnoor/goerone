<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product_types extends Model
{
    use HasFactory;

    protected $table = 'product_types';

    protected $fillable = [
        'products_id',
        'title',
        'images',
        
    ];

    protected $dates = ['created_at', 'updated_at'];
}
