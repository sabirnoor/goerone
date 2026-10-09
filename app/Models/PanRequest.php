<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanRequest extends Model
{
    protected $fillable = [
        'user_id',
        'pan',
        'name_as_per_pan',
        'date_of_birth',
        'reason',
        'status',
        'response',
        'unique_request_number',
        'verified_at'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
