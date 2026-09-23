<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StoresMapping extends Model
{
    protected $table = 'stores_mapping';
    protected $fillable = [
        '*',
    ];
}
