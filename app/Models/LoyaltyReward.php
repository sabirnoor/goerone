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

    protected $casts = [
    'maxdiscount_slabs' => 'array',
    'store_maxdiscount_slabs' => 'array',
    ];

    /* ------------------------------------------------------------------
     * Store-created reward / approval helpers
     *
     * A reward is "store-created" when it has a non-deleted stores_mapping
     * row whose created_by_store is not null (only the store-user path in
     * addreward() ever writes that column).
     * ------------------------------------------------------------------ */

    /** Deal fields only admin can see/set on store-created rewards. */
    public const ADMIN_ONLY_FIELDS = [
        'rewardtype', 'rewardvalue', 'max_reward_value',
        'custvalue', 'ownervalue', 'maxdiscount_slabs', 'goerone_discount_type',
    ];

    /** What a store-created row holds until admin fills them in. */
    public const ADMIN_FIELD_DEFAULTS = [
        'rewardtype' => 0, 'rewardvalue' => 0, 'max_reward_value' => 0,
        'custvalue' => 0, 'ownervalue' => 0, 'maxdiscount_slabs' => '[]', 'goerone_discount_type' => 0,
    ];

    /**
     * reward.discount_enabled (one column instead of two flags):
     *   0 = GoerOne, 1 = Store, 2 = Both, NULL = no customer discount (cashback only).
     */
    public const DISCOUNT_GOERONE = 0;
    public const DISCOUNT_STORE   = 1;
    public const DISCOUNT_BOTH    = 2;

    public static function goeroneEnabled($value): bool
    {
        return $value !== null && $value !== '' && in_array((int) $value, [self::DISCOUNT_GOERONE, self::DISCOUNT_BOTH], true);
    }

    public static function storeEnabled($value): bool
    {
        return $value !== null && $value !== '' && in_array((int) $value, [self::DISCOUNT_STORE, self::DISCOUNT_BOTH], true);
    }

    public static function buildDiscountEnabled(bool $goerone, bool $store): ?int
    {
        if ($goerone && $store) {
            return self::DISCOUNT_BOTH;
        }
        if ($goerone) {
            return self::DISCOUNT_GOERONE;
        }
        return $store ? self::DISCOUNT_STORE : null;
    }

    /** null when the admin-only values are complete and valid, else the message to show. */
    public static function adminFieldsError($r): ?string
    {
        if ((float) $r->rewardvalue > 0 && (float) $r->max_reward_value <= 0) {
            return 'Enter the Maximum cashback value when a cashback value is set.';
        }
        if (!self::goeroneEnabled($r->discount_enabled)) {
            if (!self::storeEnabled($r->discount_enabled) && (float) $r->rewardvalue <= 0) {
                return 'Enable at least one customer discount (GoerOne or Store) or set a cashback value before approving.';
            }
            return null; // GoerOne discount is off, so there is nothing else for admin to fill
        }
        $cust = (float) $r->custvalue;
        $type = (int) $r->goerone_discount_type;   // 0 = percentage, 1 = fixed
        if ($cust <= 0 || ($type === 0 && $cust > 100)) {
            return $type === 0
                ? 'Enter the GoerOne customer discount (between 1% and 100%) before approving.'
                : 'Enter the GoerOne customer discount amount before approving.';
        }
        [, $slabError] = self::normalizeSlabs($r->maxdiscount_slabs, $type, $cust);
        return $slabError;
    }

    /** Copy admin-only values (raw DB values) onto the root row and all its program rows. */
    public static function syncAdminFields($AgencyID, int $rootId, array $values): void
    {
        self::where('AgencyID', $AgencyID)
            ->where(function ($q) use ($rootId) {
                $q->where('reward_id', $rootId)->orWhere('parent_id', $rootId);
            })
            ->update(array_intersect_key($values, array_flip(array_merge(self::ADMIN_ONLY_FIELDS, ['discount_enabled']))));
    }

    /** Who is allowed to approve / reject. Store users never can. Change here only. */
    public static function canApprove($User): bool
    {
        return isset($User->UserType);
    }

    /** created_by_store of a reward row (null when admin-created). */
    public static function getCreatedByStore($rewardId)
    {
        return StoresMapping::where('reward_id', $rewardId)
            ->where('isdelete', 0)
            ->whereNotNull('created_by_store')
            ->orderBy('id')
            ->value('created_by_store');
    }

    public static function isStoreCreated($rewardId): bool
    {
        return !empty(self::getCreatedByStore($rewardId));
    }

    /** reward_id => {created_by_store, store_name} for a set of reward rows. */
    public static function creatorMap(array $rewardIds)
    {
        if (empty($rewardIds)) {
            return collect();
        }

        return StoresMapping::whereIn('reward_id', $rewardIds)
            ->where('isdelete', 0)
            ->whereNotNull('created_by_store')
            ->orderBy('id')
            ->get(['reward_id', 'created_by_store', 'store_name'])
            ->groupBy('reward_id')
            ->map(fn ($rows) => $rows->first());
    }

    /** Attach the flags the UI needs to a reward row. */
   
    public static function decorate($reward, $User, $creators)
    {
        $creator = $creators->get($reward->reward_id);
        $createdBy = $creator->created_by_store ?? null;
        $isStoreViewer = !isset($User->UserType);

        $reward->created_by_store = $createdBy;
        $reward->created_by_store_name = $creator->store_name ?? null;
        $reward->is_store_created = !empty($createdBy);
        $reward->viewer_is_store = $isStoreViewer;

        // Stores can edit only rewards they created.
        $reward->can_edit = $isStoreViewer
            ? (!empty($createdBy) && (int) $createdBy === (int) $User->id)
            : true;

        // Admin cannot change the store assignment of a store-created reward.
        $reward->lock_stores = !$isStoreViewer && !empty($createdBy);

        // Keep approval_status numeric: 0 pending, 1 approved, 2 rejected.
        // Admin-created rewards do not require store approval.
        if (empty($createdBy)) {
            $reward->approval_status = null;
        }

        if ($isStoreViewer) {
        $reward->makeHidden(self::ADMIN_ONLY_FIELDS);   // stores never receive these
        } else {
            $reward->admin_fields_missing = !empty($createdBy)
                && (int) $reward->approval_status !== 1
                && self::adminFieldsError($reward) !== null;
        }

        return $reward;
    }


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
        $query = LoyaltyReward::select(
            'reward.*',
            DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description',
            DB::raw('GROUP_CONCAT(CONCAT(stores_mapping.stores_id, ":", stores_mapping.store_name) SEPARATOR ", ") as store_info')
        )
            ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
            ->leftJoin('stores_mapping', function ($join) use ($User) {
                $join->on('stores_mapping.reward_id', '=', 'reward.reward_id')
                    ->where('stores_mapping.isdelete', 0);
            // Store users should only see their own store mapping.
            if (!isset($User->UserType)) {
                $join->where('stores_mapping.stores_id', $User->id);
            }
            })
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('reward.AgencyID', $User->id);
                } else {
                    // $query->where('reward.UserSysId', $User->id);
                    $query->where('reward.UserSysId', $User->id)
                ->orWhereExists(function ($subQuery) use ($User) {
                    $subQuery->select(DB::raw(1))
                        ->from('stores_mapping as sm')
                        ->whereColumn('sm.reward_id', 'reward.reward_id')
                        ->where('sm.stores_id', $User->id)
                        ->where('sm.isdelete', 0);
                });
                }
            })
            ->where('reward.parent_id', 0)
            ->groupBy('reward.reward_id')
            ->orderBy('reward.reward_id', 'DESC');
            // ->paginate($perPage);
            $storeCreated = function ($q) {
            $q->select(DB::raw(1))
                ->from('stores_mapping as smc')
                ->whereColumn('smc.reward_id', 'reward.reward_id')
                ->where('smc.isdelete', 0)
                ->whereNotNull('smc.created_by_store');
            };
            $createdBy = $post['created_by'] ?? 'all';
            $status    = $post['status'] ?? 'all';

            if ($createdBy === 'store') {
                $query->whereExists($storeCreated);
            } elseif ($createdBy === 'admin') {
                $query->whereNotExists($storeCreated);
            }

            $approval = ['pending' => 0, 'approved' => 1, 'rejected' => 2];
            if (isset($approval[$status])) {
                // approval states only exist for store-created rewards
                $query->whereExists($storeCreated)->where('reward.approval_status', $approval[$status]);
            } elseif ($status === 'active') {
                $query->where('reward.is_active', 1);
            } elseif ($status === 'inactive') {
                $query->where('reward.is_active', 0);
            }

            $keyword = trim($post['keyword'] ?? '');
            if ($keyword !== '') {
                $like = '%' . $keyword . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('reward.reward_name', 'like', $like)
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('stores_mapping as sk')
                            ->whereColumn('sk.reward_id', 'reward.reward_id')
                            ->where('sk.isdelete', 0)
                            ->where('sk.store_name', 'like', $like);
                    });
                });
            }

            $rewards = $query->paginate($perPage);

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
        $creators = self::creatorMap($parentIds);
        $rewards->getCollection()->transform(function ($reward) use ($childPrograms, $creators, $User) {
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
            return self::decorate($reward, $User, $creators);
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
            ->leftJoin('stores_mapping', function ($join) use ($User) {
                $join->on('stores_mapping.reward_id', '=', 'reward.reward_id')
                    ->where('stores_mapping.isdelete', 0);
                // Store users should only see their own store mapping.
                if (!isset($User->UserType)) {
                    $join->where('stores_mapping.stores_id', $User->id);
                }
            })
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('reward.AgencyID', $User->id);
                } else {
                    $query->where('reward.UserSysId', $User->id)
                    ->orWhereExists(function ($subQuery) use ($User) {
                    $subQuery->select(DB::raw(1))
                        ->from('stores_mapping as sm')
                        ->whereColumn('sm.reward_id', 'reward.reward_id')
                        ->where('sm.stores_id', $User->id)
                        ->where('sm.isdelete', 0);
                });
                }
            })
            // ->where('reward.is_active', 1)
            ->where('reward.parent_id', $parent_id)
            ->groupBy('reward.reward_id')
            ->orderBy('reward.reward_id', 'DESC')
            ->get();

        $creators = self::creatorMap($responsedata->pluck('reward_id')->all());
        $responsedata->transform(fn ($reward) => self::decorate($reward, $User, $creators));

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
            'reward.max_reward_value',
            'reward.maxdiscount_slabs',
            'reward.discount_enabled',
            'reward.goerone_discount_type',
            'reward.store_disc_type',
            'reward.store_disc_value',
            'reward.store_maxdiscount_slabs'
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

        // Pick the reward with the highest *effective customer discount value* per store.
        return $rewards->groupBy('stores_id')->map(function ($storeRewards) {
            return $storeRewards
                ->sortByDesc(fn ($reward) => self::effectiveCustomerValue($reward))
                ->first();
        });
    }

/**
 * Effective discount actually available to the customer.
 *
 * custvalue is always a percentage of the deal pool (dealvalue), regardless
 * of dealtype. dealtype only determines the *unit* dealvalue is expressed in:
 *   - dealtype 0 (percentage): dealvalue is "% off order" -> result is percentage points
 *   - dealtype 1 (fixed):      dealvalue is a flat amount  -> result is currency units
 *
 * maxdiscountvalue, when set, caps the result regardless of dealtype.
 */
    private static function effectiveCustomerValue($reward): float
    {
        // Rows saved before discount_enabled existed: old rule (custvalue is a % share of dealvalue).
        if ($reward->discount_enabled === null || $reward->discount_enabled === '') {
            $share = (float) $reward->custvalue / 100;

            if ((int) $reward->dealtype === 1) {
                $cap  = self::highestSlabDiscount($reward);
                $pool = $cap > 0 ? min((float) $reward->dealvalue, $cap) : (float) $reward->dealvalue;
                return $pool * $share;
            }

            return (float) $reward->dealvalue * $share; // % deal, compared as a percentage
        }

        $total = 0.0;

        // GoerOne discount: custvalue is the discount itself, in goerone_discount_type units (0 = %, 1 = fixed)
        if (self::goeroneEnabled($reward->discount_enabled)) {
            $value = (float) $reward->custvalue;
            if ((int) $reward->goerone_discount_type === 1) {
                $cap   = self::highestSlabDiscount($reward);
                $value = $cap > 0 ? min($value, $cap) : $value;
            }
            $total += $value;
        }

        // Store discount: store_disc_value in store_disc_type units (0 = %, 1 = fixed)
        if (self::storeEnabled($reward->discount_enabled)) {
            $value = (float) $reward->store_disc_value;
            if ((int) $reward->store_disc_type === 1) {
                $cap   = self::highestSlabDiscount($reward, 'store_maxdiscount_slabs');
                $value = $cap > 0 ? min($value, $cap) : $value;
            }
            $total += $value;
        }

        return $total;
    }

    /** Highest cap across slabs, for listing/ranking where no order amount is known. */
    public static function highestSlabDiscount($reward, string $field = 'maxdiscount_slabs'): float
    {
        $slabs = $reward->{$field};
        if (is_string($slabs)) {
            $slabs = json_decode($slabs, true);
        }
        return (float) collect($slabs)->max('max_discount');
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

    // public static function getStoreRewardForStoreDetail($User, $perPage, $page, $post = array(),$userMembershipAmount = null)
    // {
    //     $AgencyID = $User->UserType == 1 ? $User->id : $User->AgencyID;
    //     $responsedata = LoyaltyReward::select(
    //         'reward.*',
    //         DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
    //         'loyalty_program.program_name',
    //         'loyalty_program.description as program_description',
    //     )
    //         ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
    //         ->leftJoin('stores_mapping as sm', 'sm.reward_id', '=', 'reward.reward_id')
    //         ->where('reward.AgencyID', $AgencyID)
    //         ->whereExists(function ($query) use ($post) {
    //             $query->select(DB::raw(1))
    //                 ->from('stores_mapping')
    //                 ->whereRaw('stores_mapping.reward_id = reward.reward_id')
    //                 ->where('stores_mapping.isdelete', 0)
    //                 ->where('stores_mapping.stores_id', $post['stores_id']);
    //         })->where('reward.is_active', 1)
    //         ->groupBy('reward.reward_id')
    //         ->orderBy('reward.reward_id', 'DESC')
    //         ->paginate($perPage, ['*'], 'reward_page', $page);

    //     return $responsedata;
    // }

  
    public static function getStoreRewardForStoreDetail( $User, $perPage, $page, $post = [], $userMembershipAmount = null) {
        $AgencyID = $User->UserType == 1 ? $User->id : $User->AgencyID;

        $query = LoyaltyReward::select(
            'reward.*',
            DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description'
        )
            ->leftJoin(
                'loyalty_program',
                'loyalty_program.program_id',
                '=',
                'reward.program_id'
            )
            ->where('reward.AgencyID', $AgencyID)
            ->where('reward.is_active', 1)
            ->whereExists(function ($subQuery) use ($post) {
                $subQuery->select(DB::raw(1))
                    ->from('stores_mapping')
                    ->whereColumn(
                        'stores_mapping.reward_id',
                        'reward.reward_id'
                    )
                    ->where('stores_mapping.isdelete', 0)
                    ->where('stores_mapping.stores_id', $post['stores_id']);
            });

        // Customers with membership only see benefits for
        // memberships priced at or above their own membership.
        if ($userMembershipAmount !== null) {
            $query->where(
                'loyalty_program.membership_amount',
                '>=',
                $userMembershipAmount
            );
        }

        return $query
            ->orderBy('reward.reward_id', 'DESC')
            ->paginate(
                $perPage,
                ['*'],
                'reward_page',
                $page
            );
    }


    /**
    * Validate slabs and rebuild them contiguously.
    * Input rows: [{to, max_discount}, ...] (last row's "to" is ignored = "and above")
    * @return array [$slabs|null, $error|null]
    */
    public static function normalizeSlabs($raw, int $dealtype, float $dealvalue): array
    {
    $rows = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($rows) || empty($rows)) {
        return [null, 'Add at least one maximum discount slab.'];
    }

    $rows   = array_values($rows);
    $count  = count($rows);
    $prevTo = 0.0;
    $out    = [];

    foreach ($rows as $i => $row) {
        $n        = $i + 1;
        $discount = $row['max_discount'] ?? null;

        if (!is_numeric($discount) || (float) $discount <= 0) {
            return [null, "Slab {$n}: enter a maximum discount greater than 0."];
        }
        $discount = (float) $discount;

        // Fixed deal: max discount can never exceed the total value
        if ($dealtype === 1 && $discount > $dealvalue) {
            return [null, "Slab {$n}: max discount cannot be more than the total value ({$dealvalue}) for fixed deals."];
        }

        if ($i === $count - 1) {
            $to = null; // open-ended last slab
        } else {
            $to = $row['to'] ?? null;
            if (!is_numeric($to) || (float) $to <= $prevTo) {
                return [null, "Slab {$n}: 'up to' value must be greater than {$prevTo}."];
            }
            $to = (float) $to;
        }

        $out[] = ['from' => $prevTo, 'to' => $to, 'max_discount' => $discount];
        if ($to !== null) {
            $prevTo = $to;
        }
    }

    return [$out, null];
    }

    /**
     * Admin approval queue: store-created parent rewards.
     * $status: pending | rejected | approved | all
     */
    public static function getApprovalList($User, $perPage, $status = 'pending')
    {
        $AgencyID = $User->UserType == 1 ? $User->id : $User->AgencyID;

        $query = LoyaltyReward::select(
            'reward.*',
            DB::raw('DATE_FORMAT(reward.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            DB::raw('(SELECT COUNT(*) FROM reward c WHERE c.parent_id = reward.reward_id) as child_count')
        )
            ->leftJoin('loyalty_program', 'loyalty_program.program_id', '=', 'reward.program_id')
            ->where('reward.AgencyID', $AgencyID)
            ->where('reward.parent_id', 0)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('stores_mapping as smc')
                    ->whereColumn('smc.reward_id', 'reward.reward_id')
                    ->where('smc.isdelete', 0)
                    ->whereNotNull('smc.created_by_store');
            });

        if ($status === 'pending') {
        $query->where('reward.approval_status', 0);
        } elseif ($status === 'rejected') {
            $query->where('reward.approval_status', 2);
        } elseif ($status === 'approved') {
            $query->where('reward.approval_status', 1);
        }

        $rewards = $query->orderBy('reward.reward_id', 'DESC')->paginate($perPage);

        $creators = self::creatorMap($rewards->pluck('reward_id')->all());
        $rewards->getCollection()->transform(fn ($reward) => self::decorate($reward, $User, $creators));

        return $rewards;
    }

    /**
     * Approve or reject a store-created reward. Applies to the parent row and
     * all child (per-program) rows. Returns [bool success, string message].
     */
    public static function setApprovalStatus($User, $rewardId, bool $approve, $reason = null): array
    {
        $AgencyID = $User->UserType == 1 ? $User->id : $User->AgencyID;

        $row = LoyaltyReward::where('AgencyID', $AgencyID)->where('reward_id', $rewardId)->first();
        if (!$row) {
            return [false, 'Reward not found.'];
        }

        $rootId = $row->parent_id > 0 ? $row->parent_id : $row->reward_id;
        if (!self::isStoreCreated($rootId)) {
            return [false, 'Only rewards created by a store need approval.'];
        }

        if ($approve) {
        $root = LoyaltyReward::where('AgencyID', $AgencyID)->where('reward_id', $rootId)->first();
        if (!$root) {
            return [false, 'Reward not found.'];
        }
        if ($error = self::adminFieldsError($root)) {
            return [false, $error];
        }
        // program rows must carry the same values as the root before they go live
        self::syncAdminFields($AgencyID, $rootId, [
            'rewardtype'        => $root->rewardtype,
            'rewardvalue'       => $root->rewardvalue,
            'max_reward_value'  => $root->max_reward_value,
            'custvalue'         => $root->custvalue,
            'ownervalue'        => $root->ownervalue,
            'discount_enabled'  => $root->discount_enabled,
            'goerone_discount_type' => $root->goerone_discount_type,
            'maxdiscount_slabs' => $root->getRawOriginal('maxdiscount_slabs'),
        ]);
        }

        $data = $approve ? ['approval_status' => 1,'is_active' => 1,'reject_reason' => null,
                ]: ['approval_status' => 2, 'is_active'  => 0, 'reject_reason' => $reason];

        LoyaltyReward::where('AgencyID', $AgencyID)
            ->where(function ($q) use ($rootId) {
                $q->where('reward_id', $rootId)->orWhere('parent_id', $rootId);
            })
            ->update($data);

        return [true, $approve ? 'Reward approved and now active.' : 'Reward rejected.'];
    }
}