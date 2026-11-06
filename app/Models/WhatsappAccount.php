<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappAccount extends Model
{
    protected $table = 'whatsapp_accounts';
    protected $fillable = [
        'AgencyID',
        'UserSysId',
        'agent_id',
        'meta_user_id',
        'phone_number_id',
        'waba_id',
        'business_id',
        'user_access_token',
        'app_id',
        'app_secret',
        'webhook_verify_token',
        'meta_app_access_token',
        'whatsapp_access_token',
        'phone_number',
        'business_name',
        'status',

        'meta_data',
        'connected_at',
        'updated_at',

        'refresh_token',
        'token_expires_at',
        'available_businesses',
        'available_wabas',
        'available_phone_numbers'
    ];
    protected $primaryKey = 'id';
    protected $hidden = ['updated_at'];

    public function agency()
    {
        return $this->belongsTo(Users::class, 'AgencyID');
    }

    // Add these constants
    const STATUS_DISCONNECTED = 'disconnected';
    const STATUS_PENDING = 'pending';
    const STATUS_CONNECTED = 'connected';

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function messages()
    {
        return $this->hasMany(WhatsappMessage::class);
    }
    // Check if token is expired
    public function isTokenExpired()
    {
        if (!$this->token_expires_at) {
            return true; // No expiration date means expired
        }

        // Ensure it's a Carbon instance
        if (is_string($this->token_expires_at)) {
            $this->token_expires_at = \Carbon\Carbon::parse($this->token_expires_at);
        }

        return $this->token_expires_at->isPast();
    }

    // Scope for connected accounts
    public function scopeConnected($query)
    {
        return $query->where('status', self::STATUS_CONNECTED);
    }

    // Get display name
    public function getDisplayNameAttribute()
    {
        return $this->business_name ?: $this->phone_number ?: 'WhatsApp Account';
    }
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
