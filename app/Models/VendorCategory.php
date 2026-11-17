<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorCategory extends Model
{
    protected $fillable = ['name', 'is_food', 'icon', 'sort_order', 'is_active'];

    protected $casts = [
        'is_food' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function types()
    {
        return $this->hasMany(VendorType::class)->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', 1);
    }
}