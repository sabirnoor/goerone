<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'agency_id',
        'total_points_redeemed',
        'redemption_code',
        'status',
        'notes'
    ];

    protected $casts = [
        'total_points_redeemed' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function items()
    {
        return $this->hasMany(RewardRedemptionItem::class,'redemption_id');
    }

    public function rewardEarns()
    {
        return $this->hasManyThrough(RewardEarn::class, RewardRedemptionItem::class, 'redemption_id', 'id');
    }

    public static function generateRedemptionCode()
    {
        do {
            $code = 'RED-' . strtoupper(substr(md5(uniqid()), 0, 8));
        } while (self::where('redemption_code', $code)->exists());

        return $code;
    }
}