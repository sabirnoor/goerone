<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationMaster extends Model
{
    protected $table = 'notification_master';

    protected $guarded = [];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];
}