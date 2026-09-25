<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LoyaltyReward extends Model
{
    protected $table = 'reward';
    protected $fillable = [
        '*',
    ];

    public static function getloyaltyReward($User, $perPage, $post = array())
    {
        // $responsedata = LoyaltyReward::select(
        //     'reward.*',
        //     DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
        //     'loyalty_program.program_name',
        //     'loyalty_program.description as program_description',
        // )->leftjoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
        //     ->where(function ($query) use ($User) {
        //         if ($User->UserType == 1) {
        //             $query->where('reward.AgencyID', $User->id);
        //         } else {
        //             $query->where('reward.UserSysId', $User->id);
        //         }
        //     })->orderBy('reward.reward_id', 'DESC')->paginate($perPage);

        // // $responsedata = LoyaltyReward::select(
        // //     'reward.*',
        // //     DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
        // //     'loyalty_program.program_name',
        // //     'loyalty_program.description as program_description',
        // //     DB::raw('GROUP_CONCAT(CONCAT(stores_mapping.stores_id, ":", stores_mapping.store_name) SEPARATOR ", ") as store_info')
        // // )
        // //     ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
        // //     // ->leftJoin('stores_mapping', 'stores_mapping.reward_id', '=', 'reward.reward_id')
        // //     ->leftJoin('stores_mapping', function ($join) {
        // //         $join->on('stores_mapping.reward_id', '=', 'reward.reward_id')
        // //             ->where('stores_mapping.isdelete', 0);
        // //     })
        // //     ->where(function ($query) use ($User) {
        // //         if ($User->UserType == 1) {
        // //             $query->where('reward.AgencyID', $User->id);
        // //         } else {
        // //             $query->where('reward.UserSysId', $User->id);
        // //         }
        // //     })
        // //     // ->where('reward.is_active', 1)
        // //     ->where('reward.parent_id', 0)
        // //     ->groupBy('reward.reward_id')
        // //     ->orderBy('reward.reward_id', 'DESC')
        // //     ->paginate($perPage);


        // 1️⃣ Get parent rewards (parent_id = 0)
        // 1️⃣ Get parent rewards (parent_id = 0)
        $rewards = LoyaltyReward::select(
            'reward.*',
            DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description',
            DB::raw('GROUP_CONCAT(CONCAT(stores_mapping.stores_id, ":", stores_mapping.store_name) SEPARATOR ", ") as store_info')
        )
            ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
            ->leftJoin('stores_mapping', function ($join) {
                $join->on('stores_mapping.reward_id', '=', 'reward.reward_id')
                    ->where('stores_mapping.isdelete', 0);
            })
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('reward.AgencyID', $User->id);
                } else {
                    $query->where('reward.UserSysId', $User->id);
                }
            })
            ->where('reward.parent_id', 0)
            ->groupBy('reward.reward_id')
            ->orderBy('reward.reward_id', 'DESC')
            ->paginate($perPage);

        // 2️⃣ Get all child programs for all these parent rewards in one query
        $parentIds = $rewards->pluck('reward_id')->toArray();

        $childPrograms = LoyaltyReward::select(
            'reward.reward_id',
            'reward.parent_id',
            'reward.program_id as id',
            'loyalty_program.program_name as name'
        )
            ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
            ->whereIn('reward.parent_id', $parentIds)
            ->get()
            ->groupBy('parent_id'); // group by parent reward_id

        // 3️⃣ Attach program list to each parent reward (include parent’s own program)
        $rewards->getCollection()->transform(function ($reward) use ($childPrograms) {
            // Parent program
            $parentProgram = collect([
                (object)[
                    'reward_id' => $reward->reward_id,
                    'id' => $reward->program_id,
                    'name' => $reward->program_name,
                ]
            ]);
            // Child programs
            $childProgramList = $childPrograms->get($reward->reward_id, collect());
            // Merge both (parent first)
            $reward->program = $parentProgram->merge($childProgramList)->values();
            return $reward;
        });


        return $rewards;
    }
    public static function getloyaltyRewardParent($User, $parent_id)
    {
        $responsedata = LoyaltyReward::select(
            'reward.*',
            DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description',
            DB::raw('GROUP_CONCAT(CONCAT(stores_mapping.stores_id, ":", stores_mapping.store_name) SEPARATOR ", ") as store_info')
        )
            ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
            // ->leftJoin('stores_mapping', 'stores_mapping.reward_id', '=', 'reward.reward_id')
            ->leftJoin('stores_mapping', function ($join) {
                $join->on('stores_mapping.reward_id', '=', 'reward.reward_id')
                    ->where('stores_mapping.isdelete', 0);
            })
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('reward.AgencyID', $User->id);
                } else {
                    $query->where('reward.UserSysId', $User->id);
                }
            })
            // ->where('reward.is_active', 1)
            ->where('reward.parent_id', $parent_id)
            ->groupBy('reward.reward_id')
            ->orderBy('reward.reward_id', 'DESC')
            ->get();

        return $responsedata;
    }
    public static function getStoreReward($User, $perPage, $post = array())
    {
        // $responsedata = LoyaltyReward::select(
        //     'reward.*',
        //     DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
        //     'loyalty_program.program_name',
        //     'loyalty_program.description as program_description',
        // )->leftjoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
        //     ->where(function ($query) use ($User) {
        //         if ($User->UserType == 1) {
        //             $query->where('reward.AgencyID', $User->id);
        //         } else {
        //             $query->where('reward.UserSysId', $User->id);
        //         }
        //     })->where(function ($query) use ($post) {
        //         if ($post['stores_id'] > 0) {
        //             $query->where('reward.stores_id', $post['stores_id']);
        //         }
        //     })->orderBy('reward.reward_id', 'DESC')->paginate($perPage);

        $storeId = 4;

        $responsedata = LoyaltyReward::select(
            'reward.*',
            DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description',
            // DB::raw('GROUP_CONCAT(CONCAT(sm.stores_id, ":", sm.store_name) SEPARATOR ", ") as store_info')
        )
            ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
            ->leftJoin('stores_mapping as sm', 'sm.reward_id', '=', 'reward.reward_id') // join for GROUP_CONCAT
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('reward.AgencyID', $User->id);
                } else {
                    $query->where('reward.UserSysId', $User->id);
                }
            })
            ->whereExists(function ($query) use ($post) {
                $query->select(DB::raw(1))
                    ->from('stores_mapping')
                    ->whereRaw('stores_mapping.reward_id = reward.reward_id')
                    ->where('stores_mapping.isdelete', 0)
                    ->where('stores_mapping.stores_id', $post['stores_id']);
            })->where('reward.is_active', 1)
            ->groupBy('reward.reward_id')
            ->orderBy('reward.reward_id', 'DESC')
            ->paginate($perPage);


        return $responsedata;
    }


    /**
     * For a set of store IDs, return the single best (highest discount) active
     * reward per store, to be shown on searchNearby / searchDiscover listings.
     *
     * - reward.is_active must be 1
     * - reward must be mapped to the store via stores_mapping (isdelete = 0)
     * - Carbon::now() must fall between reward.start_date and reward.end_date
     * - "best" reward per store = highest reward.custvalue
     *   (dealtype 0 = percentage, 1 = fixed amount — custvalue is compared as-is)
     * - ordervalue is the minimum order amount required to avail the offer
     *
     * @param array $storeIds
     * @param int|null $AgencyID
     * @return \Illuminate\Support\Collection keyed by stores_id
     */
    public static function getBestRewardsForStores(array $storeIds, $AgencyID = null)
    {
        if (empty($storeIds)) {
            return collect();
        }

        $now = Carbon::now();

        $query = LoyaltyReward::select(
            'reward.reward_id',
            'stores_mapping.stores_id',
            'reward.dealtype',
            'reward.dealvalue',
            'reward.custvalue',
            'reward.ownervalue',
            'reward.rewardtype',
            'reward.rewardvalue',
            'reward.ordervalue',
            'reward.start_date',
            'reward.end_date',
            'reward.maxdiscountvalue',
            'reward.max_reward_value',
        )
            ->join('stores_mapping', function ($join) {
                $join->on('stores_mapping.reward_id', '=', 'reward.reward_id')
                    ->where('stores_mapping.isdelete', 0);
            })
            ->whereIn('stores_mapping.stores_id', $storeIds)
            ->where('reward.is_active', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('reward.start_date')
                    ->orWhere('reward.start_date', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('reward.end_date')
                    ->orWhere('reward.end_date', '>=', $now);
            });

        if (!empty($AgencyID)) {
            $query->where('reward.AgencyID', $AgencyID);
        }

        $rewards = $query->get();

        // Pick the highest custvalue reward per store.
        return $rewards->groupBy('stores_id')->map(function ($storeRewards) {
            return $storeRewards->sortByDesc('custvalue')->first();
        });
    }

    public static function getCustomerReward($User, $post = array())
    {
        $userInfo = LoyaltyUserCard::select(
            'user_card.*',
            DB::raw('DATE_FORMAT(user_card.created_at, "%d %b, %Y") as createdDate'),
        )->leftjoin('users', 'users.id', '=', 'user_card.user_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('user_card.AgencyID', $User->id);
                } else {
                    $query->where('user_card.AgencyID', $User->AgencyID);
                }
            })->where('user_card.status', 'active')->where('user_card.user_id', $User->id)->first();

        // pr($userInfo);
        // pr($User->details->VIPAgency);
        // die;
        if ($userInfo) {
            $responsedata = LoyaltyCard::select(
                'loyalty_card.*',
                DB::raw('DATE_FORMAT(loyalty_card.created_at, "%d %b, %Y") as createdDate'),
                'loyalty_program.program_name',
                'loyalty_program.description as program_description',
                'reward.reward_id',
                'reward.reward_name',
                'reward.reward_logo',
                'reward.description',
                'stores.id as store_id',
                'stores.vendortype',
                'stores.store_name',
                'stores.phone',
                'stores.address',
                'stores.store_logo',
                'stores.contact_name',
                'stores.description',
                'stores.lat',
                'stores.lon',
                'stores.type as store_type',
                'stores_mapping.AgencyID as MapAgencyID',
                'static_cities.fullRegionName',
                //DB::raw('GROUP_CONCAT(CONCAT(stores_mapping.stores_id, ":", stores_mapping.store_name) SEPARATOR ", ") as store_info')
            )->leftjoin('loyalty_program', 'loyalty_program.program_id', '=', 'loyalty_card.program_id')
                ->leftjoin('reward', 'reward.program_id', '=', 'loyalty_card.program_id')
                ->leftjoin('stores_mapping', 'reward.reward_id', '=', 'stores_mapping.reward_id')
                ->leftjoin('stores', 'stores_mapping.stores_id', '=', 'stores.id')
                ->leftjoin('static_cities', 'static_cities.id', '=', 'stores.city')
                // ->leftJoin('stores_mapping', function ($join) use ($User) {
                //     $join->on('stores_mapping.reward_id', '=', 'reward.reward_id')->where('stores_mapping.isdelete', 0)
                //         ->where('stores_mapping.AgencyID', $User->AgencyID);
                // })
                ->where(function ($query) use ($User) {
                    if ($User->UserType == 1) {
                        $query->where('loyalty_card.AgencyID', $User->id);
                    } else {
                        $query->where('loyalty_card.AgencyID', $User->AgencyID);
                    }
                })->where('loyalty_card.card_id', $userInfo->card_id)
                ->where('reward.AgencyID', $User->AgencyID)
                ->where('reward.is_active', 1)
                ->where('stores_mapping.AgencyID', $User->AgencyID)->where('stores_mapping.isdelete', 0)
                ->orderBy('loyalty_card.card_id', 'DESC')->get();
            return $responsedata;
        } else {
            return [];
        }
    }

    public static function getStoreRewardForStoreDetail($User, $perPage, $page, $post = array())
    {
        $AgencyID = $User->UserType == 1 ? $User->id : $User->AgencyID;
        $responsedata = LoyaltyReward::select(
            'reward.*',
            DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description',
        )
            ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
            ->leftJoin('stores_mapping as sm', 'sm.reward_id', '=', 'reward.reward_id')
            ->where('reward.AgencyID', $AgencyID)
            ->whereExists(function ($query) use ($post) {
                $query->select(DB::raw(1))
                    ->from('stores_mapping')
                    ->whereRaw('stores_mapping.reward_id = reward.reward_id')
                    ->where('stores_mapping.isdelete', 0)
                    ->where('stores_mapping.stores_id', $post['stores_id']);
            })->where('reward.is_active', 1)
            ->groupBy('reward.reward_id')
            ->orderBy('reward.reward_id', 'DESC')
            ->paginate($perPage, ['*'], 'reward_page', $page);

        return $responsedata;
    }
}