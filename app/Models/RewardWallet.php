<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RewardWallet extends Model
{
    use HasFactory;

    protected $table = 'reward_wallet';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ReferenceNo',
        'AgencyID',
        'UserSysId',
        'customer_id',
        'payer_id',
        'payee_id',
        'type',
        'points',
        'balance_points',
        'description',
        'status',
        'RewardMode',
        'PlanType',
        'latitude',
        'longitude',
        'device_id',
        'ip_address',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'balance_points' => 'decimal:2',
    ];

    const TYPE_CREDIT = 'CR';
    const TYPE_DEBIT = 'DR';

    const STATUS_PENDING = 'PENDING';
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_FAILED = 'FAILED';

    /**
     * Relationships
     */

    // 🧍‍♂️ The user who performed the transaction (payer / earner)
    public function payer()
    {
        return $this->belongsTo(User::class, 'payer_id');
    }
    public function payee()
    {
        return $this->belongsTo(User::class, 'payee_id');
    }
    public function agency()
    {
        return $this->belongsTo(User::class, 'AgencyID');
    }
    /**
     * Scopes
     */

    // ✅ Filter by successful transactions
    public function scopeSuccess($query)
    {
        return $query->where('status', 'SUCCESS');
    }

    // 🧾 Filter by transaction type (PAY, EARN, etc.)
    public function scopeType($query, $type)
    {
        return $query->where('type', strtoupper($type));
    }

    /**
     * Accessors & Mutators
     */

    // Format points to 2 decimal places
    public function getPointsAttribute($value)
    {
        return number_format($value, 2, '.', '');
    }

    // Automatically uppercase the type
    public function setTypeAttribute($value)
    {
        $this->attributes['type'] = strtoupper($value);
    }

    /**
     * Utility Helpers
     */

    // Generate transaction reference (if not provided)
    public static function generateTxnRef($pre = 'TRX'): string
    {
        return $pre . now()->format('YmdHis') . rand(1000, 9999);
    }

    // Check if transaction belongs to a store payment
    public function isStorePayment(): bool
    {
        return $this->type === 'DR' && !empty($this->store_id);
    }

    // Check if transaction is an earning transaction
    public function isRewardEarn(): bool
    {
        return $this->type === 'CR';
    }
}
