<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class userDevices extends Model
{
    use HasFactory;

    protected $table = 'user_devices';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'AgencyID',
        'user_id',
        'device_id',
        'device_name',
        'os',
        'is_verified'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];
}
