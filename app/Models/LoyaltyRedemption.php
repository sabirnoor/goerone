<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LoyaltyRedemption extends Model
{
    protected $table = 'redemption';
    protected $fillable = [
        '*',
    ];
    public function settlement()
    {
        return $this->belongsTo(StoreSettlements::class, 'settlement_id');
    }
    public static function getloyaltyRedemtion($User, $perPage, $post = array())
    {
        // $responsedata = LoyaltyRedemption::select(
        //     'redemption.*',
        //     'users.fname',
        //     'users.lname',
        //     'users.title',
        //     'users.name',
        //     'users.UserType',
        //     'reward.reward_name',
        //     'reward.description'
        // )->leftjoin('reward', 'reward.reward_id', '=', 'redemption.reward_id')
        //     ->leftjoin('users', 'users.id', '=', 'redemption.user_id')
        //     ->where(function ($query) use ($User) {
        //         if ($User->UserType == 1) {
        //             $query->where('redemption.AgencyID', $User->id);
        //         } else {
        //             $query->where('redemption.UserSysId', $User->id);
        //         }
        //     })->orderBy('redemption.redemption_date', 'DESC')->paginate($perPage);


        // $responsedata = LoyaltyRedemption::select(
        //     'redemption.*',
        //     'users.fname',
        //     'users.lname',
        //     'users.title',
        //     'users.name',
        //     'users.UserType',
        //     'stores_mapping.store_name',
        //     'reward.reward_name',
        //     'reward.description'
        // )
        //     ->join('stores_mapping', function ($join) use ($post) {
        //         if ($post['stores_id'] > 0) {
        //             $join->on('stores_mapping.reward_id', '=', 'redemption.reward_id')
        //                 ->where('stores_mapping.stores_id', $post['stores_id'])
        //                 ->where('stores_mapping.AgencyID', $post['AgencyID'])
        //                 ->where('stores_mapping.isdelete', 0);
        //         }
        //     })
        //     ->where(function ($query) use ($post) {
        //         if ($post['stores_id'] > 0) {
        //             $query->where('redemption.stores_id', $post['stores_id']);
        //         }
        //     })->leftJoin('reward', 'reward.reward_id', '=', 'redemption.reward_id')
        //     ->leftJoin('users', 'users.id', '=', 'redemption.user_id')
        //     ->where(function ($query) use ($User) {
        //         if ($User->UserType == 1) {
        //             $query->where('redemption.AgencyID', $User->id);
        //         } else {
        //             $query->where('redemption.UserSysId', $User->id);
        //         }
        //     })->groupBy('redemption.redemption_id')
        //     ->orderBy('redemption.redemption_date', 'DESC')->paginate($perPage);
        $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
        $responsedata = LoyaltyRedemption::select(
            'redemption.*',
            'users.mobile',
            'users.email',
            'users.fname',
            'users.lname',
            'users.title',
            'users.name',
            'users.UserType',
            'stores.store_name',
            'stores.vendortype',
            'reward.reward_name',
            'reward.description',
            // 'reward_wallet_temp.points as CashBackPending',
            // 'reward_wallet.points as CashBackApproved',
        ) // Single amount: approved if present, otherwise pending, otherwise 0
            // ->selectRaw('
            //     CASE
            //         WHEN COALESCE(reward_wallet.points, 0) > 0 THEN reward_wallet.points
            //         WHEN reward_wallet_temp.status = PENDING AND COALESCE(reward_wallet_temp.points, 0) > 0 THEN reward_wallet_temp.points
            //         ELSE 0
            //     END AS CashBack
            // ')
            // // Status: 1 = approved, 0 = pending, NULL = no cashback
            // ->selectRaw('
            //     CASE
            //         WHEN COALESCE(reward_wallet.points, 0) > 0 THEN 1
            //         WHEN reward_wallet_temp.status = PENDING AND COALESCE(reward_wallet_temp.points, 0) > 0 THEN 0
            //         ELSE 0
            //     END AS cashbackstatus
            // ')

            ->selectRaw('
                CASE
                    WHEN COALESCE(reward_wallet.points, 0) > 0
                        THEN reward_wallet.points
                    WHEN reward_wallet_temp.status = ?
                        AND COALESCE(reward_wallet_temp.points, 0) > 0
                        THEN reward_wallet_temp.points
                    ELSE 0
                END AS CashBack
            ', ['PENDING'])

            ->selectRaw('
                CASE
                    WHEN COALESCE(reward_wallet.points, 0) > 0
                        THEN 1
                    WHEN reward_wallet_temp.status = ?
                        AND COALESCE(reward_wallet_temp.points, 0) > 0
                        THEN 0
                    ELSE 0
                END AS cashbackstatus
            ', ['PENDING'])
            ->leftJoin('stores', 'stores.id', '=', 'redemption.stores_id')
            ->where(function ($query) use ($post) {
                if ($post['stores_id'] > 0) {
                    $query->where('redemption.stores_id', $post['stores_id']);
                }
            })->leftJoin('reward', 'reward.reward_id', '=', 'redemption.reward_id')
            ->leftJoin('users', 'users.id', '=', 'redemption.user_id')
            ->leftJoin('reward_wallet_temp', 'reward_wallet_temp.redemption_id', '=', 'redemption.redemption_id')
            ->leftJoin('reward_wallet', 'reward_wallet.redemption_id', '=', 'redemption.redemption_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('redemption.AgencyID', $User->id);
                } else {
                    $query->where('redemption.UserSysId', $User->id);
                }
            })->where('redemption.AgencyID', $AgencyID)->groupBy('redemption.redemption_id')
            ->orderBy('redemption.redemption_date', 'DESC')->paginate($perPage);
        // ->toRawSql();
        // pr($responsedata);die;
        return $responsedata;
    }
    public static function getloyaltyRedemtionCustomer($User, $perPage, $post = array())
    {
        $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
        $responsedata = LoyaltyRedemption::select(
            'redemption.*',
            'users.fname',
            'users.lname',
            'users.title',
            'users.name',
            'users.UserType',
            'stores.store_name',
            'stores.vendortype',
            'stores.rewardrequired',
            'reward.reward_name',
            'reward.description'
        )->leftJoin('stores', 'stores.id', '=', 'redemption.stores_id')
            ->where(function ($query) use ($post) {
                if ($post['stores_id'] > 0) {
                    $query->where('redemption.stores_id', $post['stores_id']);
                }
            })->leftJoin('reward', 'reward.reward_id', '=', 'redemption.reward_id')
            ->leftJoin('users', 'users.id', '=', 'redemption.user_id')
            ->where('redemption.user_id', $User->id)->where('redemption.AgencyID', $AgencyID)->groupBy('redemption.redemption_id')
            ->orderBy('redemption.redemption_date', 'DESC')->paginate($perPage);
        // ->toRawSql();
        // pr($responsedata);die;
        return $responsedata;
    }
    public static function getloyaltyRedemtionReport($User, $perPage, $post = array())
    {

        $responsedata = LoyaltyRedemption::select(
            'redemption.*',
            'stores.store_name',
            'stores.address',
            'stores.phone',
            'stores.contact_name',
            'stores.email',
            DB::raw('ROUND(SUM(redemption.discountOwner), 2) as total_discountOwner'),
            DB::raw('ROUND(SUM(redemption.discountCust), 2) as total_discountCust'),
            DB::raw('ROUND(SUM(redemption.order_amount), 2) as total_order_amount'),
            DB::raw('ROUND(SUM(redemption.discount), 2) as total_discount')
        )->leftJoin('stores', 'stores.id', '=', 'redemption.stores_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('redemption.AgencyID', $User->id);
                } else {
                    $query->where('redemption.UserSysId', $User->id);
                }
            })->groupBy('redemption.stores_id')
            ->orderBy('redemption.redemption_date', 'DESC')->paginate($perPage);
        // pr($responsedata);
        // die;

        // $storeTotals = LoyaltyRedemption::select(
        //     'stores_mapping.stores_id',
        //     'stores_mapping.store_name',
        //     'stores.address',
        //     'stores.phone',
        //     'stores.contact_name',
        //     'stores.email',
        //     DB::raw('SUM(redemption.order_amount) as total_order_amount'),
        //     DB::raw('SUM(redemption.discount) as total_discount')
        // )->join('stores_mapping', function ($join) use ($post) {
        //     if (isset($post['stores_id']) && $post['stores_id'] > 0) {
        //         $join->on('stores_mapping.reward_id', '=', 'redemption.reward_id')
        //             ->where('stores_mapping.stores_id', $post['stores_id'])
        //             ->where('stores_mapping.isdelete', 0);
        //     } else {
        //         $join->on('stores_mapping.reward_id', '=', 'redemption.reward_id')
        //             ->where('stores_mapping.isdelete', 0);
        //     }
        // })->leftJoin('stores', 'stores.id', '=', 'stores_mapping.stores_id')
        //     ->where(function ($query) use ($User) {
        //         if ($User->UserType == 1) {
        //             $query->where('redemption.AgencyID', $User->id);
        //         } else {
        //             $query->where('redemption.UserSysId', $User->id);
        //         }
        //     })->where(function ($query) use ($post) {
        //         if ($post['stores_id'] > 0) {
        //             $query->where('redemption.stores_id', $post['stores_id']);
        //         }
        //     })->groupBy(
        //         'stores_mapping.stores_id',
        //         'stores_mapping.store_name',
        //         'stores.address',
        //         'stores.phone',
        //         'stores.contact_name',
        //         'stores.email'
        //     )->orderBy('stores_mapping.store_name', 'ASC')->get();

        return $responsedata;
    }

    public static function getlRedemtionReportCustomer($User, $perPage, $post = array())
    {

        $storeTotals = LoyaltyRedemption::select(
            'redemption.user_id',
            'users.UserType',
            'users.mobile as phone',
            'users.name as contact_name',
            'users.email',
            DB::raw('ROUND(SUM(redemption.discountCust), 2) as total_discountCust'),
            DB::raw('ROUND(SUM(redemption.order_amount), 2) as total_order_amount'),
            DB::raw('ROUND(SUM(redemption.discount), 2) as total_discount'),

        )->leftJoin('users', 'users.id', '=', 'redemption.user_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('redemption.AgencyID', $User->id);
                } else {
                    $query->where('redemption.UserSysId', $User->id);
                }
            })->groupBy(
                'redemption.user_id',
                'users.UserType',
                'users.mobile',
                'users.name',
                'users.email'
            )->orderBy('users.name', 'ASC')->paginate($perPage);

        return $storeTotals;
    }
    public static function getlRedemtionReportReward($User, $perPage, $post = array())
    {

        $storeTotals = LoyaltyRedemption::select(
            'redemption.reward_id',
            'reward.reward_name',
            'reward.description',
            DB::raw('SUM(redemption.order_amount) as total_order_amount'),
            DB::raw('SUM(redemption.discount) as total_discount')
        )->leftJoin('reward', 'reward.reward_id', '=', 'redemption.reward_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('redemption.AgencyID', $User->id);
                } else {
                    $query->where('redemption.UserSysId', $User->id);
                }
            })->groupBy(
                'redemption.reward_id',
                'reward.reward_name',
                'reward.description',
            )->orderBy('reward.reward_name', 'ASC')->paginate($perPage);

        return $storeTotals;
    }
    public static function DownloadEventPass($User, $redem_id = '')
    {
        $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
        $responsedata = LoyaltyRedemption::select(
            'redemption.*',
            'users.fname',
            'users.lname',
            'users.title',
            'users.name',
            'users.UserType',
            'stores.store_name',
            'stores.vendortype',
            'stores.address',
            'stores.event_date',
            'stores.gatenumber',
            'stores.entry_time',
            'stores.event_time',
            'reward.reward_name',
            'reward.description',
            'reward.start_date',
            'reward.end_date'
        )->leftJoin('stores', 'stores.id', '=', 'redemption.stores_id')
            ->leftJoin('reward', 'reward.reward_id', '=', 'redemption.reward_id')
            ->leftJoin('users', 'users.id', '=', 'redemption.user_id')
            ->where('redemption.redem_id', $redem_id)
            ->where('redemption.AgencyID', $AgencyID)->groupBy('redemption.redemption_id')
            ->orderBy('redemption.redemption_date', 'DESC')->first();
        // ->toRawSql();
        // pr($responsedata);die;
        return $responsedata;
    }


    public static function StoreRedeemLedger($User, $perPage, $post = array())
    {
        $applyFilters = function ($query) use ($User, $post) {
            $query->leftJoin('stores', 'stores.id', '=', 'redemption.stores_id')
                ->where(function ($q) use ($User) {
                    if ($User->UserType == 1) {
                        $q->where('redemption.AgencyID', $User->id);
                    } else {
                        $q->where('redemption.UserSysId', $User->id);
                    }
                });

            if (!empty($post['stores_id']) && $post['stores_id'] > 0) {
                $query->where('redemption.stores_id', $post['stores_id']);
            }

            return $query;
        };

        $sumColumns = [
            DB::raw('ROUND(SUM(redemption.discountOwner), 2) as total_discountOwner'),
            DB::raw('ROUND(SUM(redemption.discountCust), 2) as total_discountCust'),
            DB::raw('ROUND(SUM(redemption.order_amount), 2) as total_order_amount'),
            DB::raw('ROUND(SUM(redemption.discount), 2) as total_discount'),
        ];

        $storeColumns = [
            'stores.store_name',
            'stores.address',
            'stores.phone',
            'stores.contact_name',
            'stores.email',
        ];

        if (!empty($post['stores_id']) && $post['stores_id'] > 0) {
            // 1) All redemptions of the single store (no groupBy)
            $redemptions = $applyFilters(
                LoyaltyRedemption::select(array_merge(['redemption.*'], $storeColumns))
            )->orderBy('redemption.redemption_date', 'DESC')
                ->paginate($perPage);

            // 2) Sums in a separate variable (over ALL rows, not just the current page)
            $totals = $applyFilters(LoyaltyRedemption::select($sumColumns))->first();

            $responsedata = [
                'redemptions' => $redemptions,
                'totals'      => [
                    'total_discountOwner' => $totals->total_discountOwner ?? 0,
                    'total_discountCust'  => $totals->total_discountCust ?? 0,
                    'total_order_amount'  => $totals->total_order_amount ?? 0,
                    'total_discount'      => $totals->total_discount ?? 0,
                ],
            ];
        } else {
            // All stores: keep grouped summary per store
            $responsedata = $applyFilters(
                LoyaltyRedemption::select(array_merge(['redemption.stores_id'], $storeColumns, $sumColumns))
            )->groupBy(
                'redemption.stores_id',
                'stores.store_name',
                'stores.address',
                'stores.phone',
                'stores.contact_name',
                'stores.email'
            )
                ->orderBy(DB::raw('MAX(redemption.redemption_date)'), 'DESC')
                ->paginate($perPage);
        }
        return $responsedata;
    }
}
