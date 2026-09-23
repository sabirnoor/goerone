<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardRedemptionItem extends Model
{
    use HasFactory;

    protected $table = 'reward_redemption_items';
    protected $fillable = [
        'redemption_id',
        'reward_earn_id',
        'points_redeemed'
    ];

    protected $casts = [
        'points_redeemed' => 'decimal:2',
    ];

    public function redemption()
    {
        return $this->belongsTo(RewardRedemption::class);
    }

    public function rewardEarn()
    {
        return $this->belongsTo(RewardEarn::class);
    }
}