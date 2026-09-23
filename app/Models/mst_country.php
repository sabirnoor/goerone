<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class mst_country extends Model
{
    protected $table = 'mst_country';
    protected $fillable = [
        'id',
        'name',
    ];
    protected $hidden = ['created_at', 'updated_at'];
}
