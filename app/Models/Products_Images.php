<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Products_Images extends Model
{
    use HasFactory;

    protected $table = 'products_images';

    protected $fillable = [
        'products_id',
        'title',
        'images',
        'is_primary'
        
    ];

    protected $dates = ['created_at', 'updated_at'];
}
