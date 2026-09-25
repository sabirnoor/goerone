<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\incorporation_details;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    // protected $fillable = ['*'];
    protected $guarded = [];
    // protected $fillable = [
    //     'name',
    //     'fname',
    //     'lname',
    //     'mobile',
    //     'UserType',
    //     'email',
    //     'password',
    //     'AgencyID',
    // ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'WalletPassCode',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_online' => 'boolean',
    ];

    public function UserMembership()
    {
        return $this->hasOne(
            LoyaltyUserCard::class,
            'user_id',
            'id'
        )
            ->where('user_card.status', 'active');
    }
    public function details()
    {
        return $this->hasOne(incorporation_details::class, 'UserSysId', 'id'); // Adjust model path if needed
    }
    public function symbal()
    {
        return $this->hasOne(mst_currency::class, 'id', 'CurrencyID'); // Adjust model path if needed
    }
    public function UserSys()
    {
        return $this->hasOne(User::class, 'id', 'UserSysId')->without('details', 'agency'); // Adjust model path if needed
    }
    public function marketPlace()
    {
        return $this->hasOne(incorporation_details::class, 'UserSysId', 'id')->with('markupplacename:id,title,whatsapp_ticket,sms_ticket');
    }

    public function detail()
    {
        return $this->hasOne(incorporation_details::class, 'UserSysId', 'id')
            ->select([
                'UserSysId',   // IMPORTANT: must include foreign key
                'logo',
                'profilepicture',
                'agencyName',
                'country',
                'state',
                'city',
                'email',
                'mobile',
                'pincode',
                'address',
                'mst_state_id',
                'address1'
            ]);
    }
    // public function agency()
    // {
    //     return $this->hasOne(incorporation_details::class, 'UserSysId', 'AgencyID')
    //         ->select([
    //             'UserSysId',   // IMPORTANT: must include foreign key
    //             'logo',
    //             'BookingAllowed',
    //             'WhatsAppAllowed',
    //             'profilepicture',
    //             'agencyName',
    //             'country',
    //             'state',
    //             'city',
    //             'email',
    //             'mobile',
    //             'pincode',
    //             'address',
    //             'address1'
    //         ]);
    // }

    public function agency()
    {
        return $this->hasOne(incorporation_details::class, 'UserSysId', 'AgencyID')
            ->select([
                'UserSysId',
                'IsProd',
                'logo',
                'BookingAllowed',
                'WhatsAppAllowed',
                'profilepicture',
                'holidays_allowed',
                'hotel_allowed',
                'flight_allowed',
                'disruption_allowed',
                'bnpl_allowed',
                'passport_allowed',
                'visa_allowed',
                'bus_allowed',
                'train_allowed',
                'franchise_allowed',
                'offline_allowed',
                'homestay_allowed',
                'agencyName',
                'country',
                'state',
                'city',
                'email',
                'mobile',
                'pincode',
                'address',
                'address1'
            ])->withDefault(function ($default, $parent) {
                // If AgencyID returned nothing, try with id
                return incorporation_details::select([
                    'UserSysId',
                    'IsProd',
                    'logo',
                    'BookingAllowed',
                    'WhatsAppAllowed',
                    'profilepicture',
                    'holidays_allowed',
                    'hotel_allowed',
                    'flight_allowed',
                    'disruption_allowed',
                    'bnpl_allowed',
                    'passport_allowed',
                    'visa_allowed',
                    'bus_allowed',
                    'train_allowed',
                    'franchise_allowed',
                    'offline_allowed',
                    'agencyName',
                    'country',
                    'state',
                    'city',
                    'email',
                    'mobile',
                    'pincode',
                    'address',
                    'address1'
                ])->where('UserSysId', $parent->id)->first() ?? null;
            });
    }

    public function UserCard()
    {
        return $this->hasOneThrough(
            LoyaltyCard::class,         // Final model you want
            LoyaltyUserCard::class,     // Intermediate model
            'user_id',                  // Foreign key on LoyaltyUserCard
            'card_id',                  // Foreign key on LoyaltyCard
            'id',                       // Local key on User
            'card_id'                   // Local key on LoyaltyUserCard
        );
    }

    protected $with = ['details', 'agency', 'symbal'];

    public function otpRequests()
    {
        return $this->hasMany(OtpRequest::class);
    }

    public function emailRequests()
    {
        return $this->hasMany(EmailRequest::class);
    }


    public function hasSufficientBalance($amount)
    {
        return $this->balance >= $amount;
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function rewards()
    {
        return $this->hasMany(RewardEarn::class, 'user_id');
    }

    // Relation with reward_earn_temp (pending)
    public function pendingRewards()
    {
        return $this->hasMany(RewardEarnTemp::class, 'user_id');
    }



    public function getAllAssignedMenus()
    {
        // Get all assigned menus with their children
        $allMenus = $this->assignedMenusWithChildren()
            ->orderBy('order')
            ->get();
        // Filter out only parent menus (where parent_id is null)
        $parentMenus = $allMenus->filter(function ($menu) {
            return $menu->parent_id === null;
        });
        // For each parent menu, filter its children to only include assigned ones
        $parentMenus->each(function ($parentMenu) use ($allMenus) {
            $parentMenu->children = $allMenus->filter(function ($menu) use ($parentMenu) {
                return $menu->parent_id === $parentMenu->id;
            })->values();
        });
        return $parentMenus;
    }


    public function scopeWithUserRewards($query, $user, $userId)
    {
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        return $query->without('details')
            ->where('users.id', $userId)
            ->where('users.AgencyID', $AgencyID)
            ->with([
                'rewards' => function ($q) use ($AgencyID) {
                    $q->where('AgencyID', $AgencyID)->where('rewardearn', '>', 0);
                },
                'pendingRewards' => function ($q) use ($AgencyID) {
                    $q->where('AgencyID', $AgencyID)->where('rewardearn', '>', 0);
                }
            ]);
    }

    public function scopeUserRewardsSummary($query, $user, $customer_id = 0)
    {
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;

        $earned = DB::table('reward_earn')
            ->select([
                'user_id',
                DB::raw('SUM(order_amount) as total_order_amount'),
                DB::raw('SUM(rewardearn) as total_reward')
            ])
            ->where('rewardearn', '>', 0)
            ->groupBy('user_id');

        // Subquery for pending rewards
        $pending = DB::table('reward_earn_temp')
            ->select([
                'user_id',
                DB::raw('SUM(order_amount) as pending_order_amount'),
                DB::raw('SUM(rewardearn) as pending_reward')
            ])
            ->where('rewardearn', '>', 0)
            ->groupBy('user_id');

        // Subquery for processed redemptions
        $redemptions = DB::table('reward_redemptions')
            ->select([
                'user_id',
                DB::raw('SUM(total_points_redeemed) as total_redeemed')
            ])
            ->where('status', 'processed')
            ->groupBy('user_id');
        return  $query->without('details')
            ->where('AgencyID', $AgencyID)
            ->where(function ($query) use ($customer_id, $user) {
                if ($customer_id > 0) {
                    $query->where('id', $customer_id);
                }
            })
            ->leftJoinSub($earned, 'earned', function ($join) {
                $join->on('users.id', '=', 'earned.user_id');
            })
            ->leftJoinSub($pending, 'pending', function ($join) {
                $join->on('users.id', '=', 'pending.user_id');
            })
            ->leftJoinSub($redemptions, 'redeemed', function ($join) {
                $join->on('users.id', '=', 'redeemed.user_id');
            })
            ->select([
                'users.id as user_id',
                'users.UserType',
                'users.mobile',
                'users.countrycode',
                'users.name',
                'users.fname',
                'users.lname',
                'users.email',
                DB::raw('COALESCE(earned.total_order_amount, 0) as total_order_amount'),
                DB::raw('COALESCE(earned.total_reward, 0) as total_available'),
                DB::raw('COALESCE(pending.pending_order_amount, 0) as pending_order_amount'),
                DB::raw('COALESCE(pending.pending_reward, 0) as pending_available'),
                DB::raw('COALESCE(redeemed.total_redeemed, 0) as total_points_redeemed'),
                // DB::raw('COALESCE(earned.total_reward, 0) - COALESCE(redeemed.total_redeemed, 0) as net_rewards')
            ])->havingRaw('total_available > 0 OR pending_available > 0 OR total_points_redeemed > 0');
    }

    public function scopeUserRewardsHistory($query, $user, $customer_id = 0, $perPage = 10, $page = 1)
    {
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        // 1️⃣ Earned Rewards (confirmed)
        $earned = DB::table('reward_earn')
            ->select([
                'user_id',
                DB::raw('SUM(order_amount) as total_order_amount'),
                DB::raw('SUM(rewardearn) as total_reward')
            ])
            ->where('rewardearn', '>', 0)
            ->groupBy('user_id');
        // 2️⃣ Pending Rewards (temporary)
        $pending = DB::table('reward_earn_temp')
            ->select([
                'user_id',
                DB::raw('SUM(order_amount) as pending_order_amount'),
                DB::raw('SUM(rewardearn) as pending_reward')
            ])
            ->where('rewardearn', '>', 0)
            ->groupBy('user_id');
        // 3️⃣ Redeemed Rewards
        $redemptions = DB::table('reward_redemptions')
            ->select([
                'user_id',
                DB::raw('SUM(total_points_redeemed) as total_redeemed')
            ])
            ->where('status', 'processed')
            ->groupBy('user_id');
        // 4️⃣ Main Query (User Summary)
        $userData = $query->without('details')
            ->where('AgencyID', $AgencyID)
            ->when($customer_id > 0, fn($q) => $q->where('id', $customer_id))
            ->leftJoinSub($earned, 'earned', fn($join) => $join->on('users.id', '=', 'earned.user_id'))
            ->leftJoinSub($pending, 'pending', fn($join) => $join->on('users.id', '=', 'pending.user_id'))
            ->leftJoinSub($redemptions, 'redeemed', fn($join) => $join->on('users.id', '=', 'redeemed.user_id'))
            ->select([
                'users.id as user_id',
                'users.UserType',
                'users.mobile',
                'users.countrycode',
                'users.name',
                'users.fname',
                'users.lname',
                'users.email',
                DB::raw('COALESCE(earned.total_order_amount, 0) as total_order_amount'),
                DB::raw('COALESCE(earned.total_reward, 0) as total_available'),
                DB::raw('COALESCE(pending.pending_order_amount, 0) as pending_order_amount'),
                DB::raw('COALESCE(pending.pending_reward, 0) as pending_available'),
                DB::raw('COALESCE(redeemed.total_redeemed, 0) as total_points_redeemed')
            ])
            ->havingRaw('total_available > 0 OR pending_available > 0 OR total_points_redeemed > 0')
            ->first();

        if ($userData) {
            // 5️⃣ Combine transaction history from both tables
            $transactionsEarned = DB::table('reward_earn')
                ->where('user_id', $userData->user_id)
                ->select('id', 'BookingID', 'is_scan_pay', 'order_amount', 'rewardearn', 'created_at', DB::raw("'earned' as source"));

            $transactionsPending = DB::table('reward_earn_temp')
                ->where('user_id', $userData->user_id)
                ->select('id', 'BookingID', 'is_scan_pay', 'order_amount', 'rewardearn', 'created_at', DB::raw("'pending' as source"));

            // Combine both using unionAll
            $combinedQuery = $transactionsEarned->unionAll($transactionsPending);

            // 6️⃣ Apply pagination manually since unionAll disables paginate()
            $total = DB::table(DB::raw("({$combinedQuery->toSql()}) as combined"))
                ->mergeBindings($combinedQuery)
                ->count();
            $transactions = DB::table(DB::raw("({$combinedQuery->toSql()}) as combined"))
                ->mergeBindings($combinedQuery)
                ->orderBy('created_at', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
            $userData->transactions = [
                'data' => $transactions,
                'pagination' => [
                    'current_page' => (int)$page,
                    'per_page' => (int)$perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage)
                ]
            ];
        }

        return $userData;
    }
}
