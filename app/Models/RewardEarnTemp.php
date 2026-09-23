<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RewardEarnTemp extends Model
{
    use HasFactory;

    protected $table = 'reward_earn_temp';

    // protected $fillable = [
    //     'user_id',
    //     'card_id',
    //     'reward_id',
    //     'AgencyID',
    //     'UserSysId'
    // ];

    protected $fillable = [
        'user_id',
        'AgencyID',
        'UserSysId',
        'rewardtype',
        'order_amount',
        'rewardearn',
        'is_redeemed',
        'redeemed_at',
        'redemption_id'
    ];

    protected $casts = [
        'order_amount' => 'decimal:2',
        'rewardearn' => 'decimal:2',
        'is_redeemed' => 'boolean',
        'redeemed_at' => 'datetime',
    ];

    /**
     * Get the user associated with the reward earning.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'AgencyID');
    }


    public static function getEarningByCustomer($User, $perPage, $post = array())
    {

        $result = RewardEarn::select(
            'reward_earn.id',
            'reward_earn.rewardtype',
            'reward_earn.order_amount',
            'reward_earn.rewardearn',
            'reward_earn.reward_id',
            'reward_earn.created_at',
            'reward.reward_name',
            'users.name',
        )->leftJoin('reward', 'reward.reward_id', '=', 'reward_earn.reward_id')
            ->leftJoin('users', 'users.id', '=', 'reward_earn.user_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('reward_earn.AgencyID', $User->id);
                } else {
                    $query->where('reward_earn.UserSysId', $User->id);
                }
            })->where(function ($query) use ($post) {
                if (isset($post['user_id']) && $post['user_id'] > 0) {
                    $query->where('reward_earn.user_id', $post['user_id']);
                }
                if (isset($post['dateFrom']) && isset($post['dateTo']) && !empty($post['dateFrom']) && !empty($post['dateTo'])) {
                    $query->whereDate('reward_earn.created_at', '>=', $post['dateFrom'])->whereDate('reward_earn.created_at', '<=', $post['dateTo']);
                    // $query->where('reward_earn.created_at', $post['dateFrom']);
                }
            })->orderBy('reward_earn.created_at', 'DESC')->paginate($perPage);

        return $result;
    }
    public static function TotalRewardEarning($User, $post = array())
    {
        $result = RewardEarnTemp::select(
            DB::raw('CAST(SUM(reward_earn_temp.rewardearn) AS DECIMAL(10,2)) as total_rewardearn'),
            DB::raw('CAST(SUM(reward_earn_temp.order_amount) AS DECIMAL(10,2)) as total_order_amount'),
            'users.id',
            'users.name',
        )
            ->leftJoin('users', 'users.id', '=', 'reward_earn_temp.user_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('reward_earn_temp.AgencyID', $User->id);
                } else {
                    $query->where('reward_earn_temp.AgencyID', $User->AgencyID);
                }
            })
            ->where(function ($query) use ($post) {
                if (isset($post['user_id']) && $post['user_id'] > 0) {
                    $query->where('reward_earn_temp.user_id', $post['user_id']);
                }
                if (isset($post['dateFrom']) && isset($post['dateTo']) && !empty($post['dateFrom']) && !empty($post['dateTo'])) {
                    $query->whereDate('reward_earn_temp.created_at', '>=', $post['dateFrom'])
                        ->whereDate('reward_earn_temp.created_at', '<=', $post['dateTo']);
                }
            })->first();
        return $result;
    }
    
}
