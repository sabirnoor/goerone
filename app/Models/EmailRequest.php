<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailRequest extends Model
{
    protected $fillable = [
        'user_id',
        'to_email',
        'subject',
        'body',
        'is_sent',
        'sent_at',
        'ip_address'
    ];

    protected $casts = [
        'is_sent' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
