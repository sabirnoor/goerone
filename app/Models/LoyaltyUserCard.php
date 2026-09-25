<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyUserCard extends Model
{
    protected $table = 'user_card';
    protected $fillable = [
        '*',
    ];
    public function LoyaltyCard()
    {
        return $this->belongsTo(
            LoyaltyCard::class,
            'card_id',
            'card_id'
        );
    }
    public function usercard()
    {
        return $this->belongsTo(LoyaltyCard::class, 'card_id', 'card_id')->with('LoyaltyProgram');
    }
    public static function getloyaltyUserCard($User, $perPage, $post = array())
    {
        $responsedata = LoyaltyUserCard::select(
            'user_card.*',
            DB::raw('DATE_FORMAT(user_card.created_at, "%d %b, %Y") as createdDate'),
            'users.title',
            'users.fname',
            'users.lname',
            'users.name',
            'users.UserType',
            'users.email',
            'users.mobile',
            'loyalty_program.program_name',
            'loyalty_card.card_type'
        )->leftjoin('users', 'users.id', '=', 'user_card.user_id')
            ->leftjoin('loyalty_card', 'loyalty_card.card_id', '=', 'user_card.card_id')
            ->leftjoin('loyalty_program', 'loyalty_program.program_id', '=', 'loyalty_card.program_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('user_card.AgencyID', $User->id);
                } else {
                    $query->where('user_card.UserSysId', $User->id);
                }
            })->where(function ($query) use ($post) {
                if (count($post['cardNumbers']) > 0) {
                    $query->whereIn('user_card.card_number', $post['cardNumbers']);
                }
                if (isset($post['Filter']['email']) && !empty($post['Filter']['email'])) {
                    $query->where('users.email', $post['Filter']['email']);
                }
                if (isset($post['Filter']['mobile']) && !empty($post['Filter']['mobile'])) {
                    $query->where('users.mobile', $post['Filter']['mobile']);
                }
                if (isset($post['Filter']['card_number']) && !empty($post['Filter']['card_number'])) {
                    $query->where('user_card.card_number', $post['Filter']['card_number']);
                }
            })->where('user_card.status', 'active')->orderBy('user_card.created_at', 'DESC')->paginate($perPage);
        return $responsedata;
    }

    public static function createUniqueCardNumber($User)
    {
        // Use a DB transaction to avoid race conditions
        $NewCardNumber = DB::transaction(function () use ($User) {
            // Get latest order number
            $lastOrder = LoyaltyUserCard::where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('AgencyID', $User->id);
                } else {
                    $query->where('UserSysId', $User->id);
                }
            })->orderBy('card_no', 'desc')->first();

            if ($lastOrder) {
                $lastNumber = intval($lastOrder->card_no);
                $newNumber = $lastNumber + 1;
            } else {
                $newNumber = 1;
            }

            // Format as 4-digit string
            $formatted = str_pad($newNumber, 4, '0', STR_PAD_LEFT);

            // Ensure it's not a duplicate (redundant if DB has unique constraint)
            if (LoyaltyUserCard::where('card_no', $formatted)->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('AgencyID', $User->id);
                } else {
                    $query->where('UserSysId', $User->id);
                }
            })->exists()) {
                throw new \Exception("Duplicate card number detected.");
            }
            // Create the order
            //LoyaltyUserCard::create(['card_no' => $formatted]);
            return $formatted;
        });

        return [
            'success' => true,
            'card_no' => $NewCardNumber,
        ];
    }


    public static function getloyaltyUserCardDetails($User, $post = array())
    {
        // $responsedata = LoyaltyUserCard::select(
        //     'user_card.*',
        //     DB::raw('DATE_FORMAT(user_card.created_at, "%d %b, %Y") as createdDate'),
        //     'users.title',
        //     'users.fname',
        //     'users.lname',
        //     'users.name',
        //     'users.UserType',
        //     'users.email',
        //     'users.mobile',
        //     'loyalty_card.expiration_date',
        //     'loyalty_card.issue_date',
        //     'loyalty_card.card_type',
        //     'loyalty_card.program_id',
        //     'reward.reward_id',
        //     'reward.reward_name',
        //     'reward.description',
        //     'reward.points_required',
        //     'reward.dealtype',
        //     'reward.dealvalue',
        //     'reward.ownervalue',
        //     'reward.custvalue',
        //     'reward.rewardtype',
        //     'reward.ordervalue',
        //     'reward.rewardvalue',
        // )->leftjoin('users', 'users.id', '=', 'user_card.user_id')
        //     ->leftjoin('loyalty_card', 'loyalty_card.card_id', '=', 'user_card.card_id')
        //     ->join('reward', 'loyalty_card.program_id', '=', 'reward.program_id')
        //     ->join('stores_mapping', function ($join) use ($post) {
        //         $join->on('reward.reward_id', '=', 'stores_mapping.reward_id')
        //             ->where('stores_mapping.stores_id', '=', $post['store_id'])
        //             ->where('stores_mapping.isdelete', '=', 0);
        //     })
        //     ->where(function ($query) use ($User) {
        //         if ($User->UserType == 1) {
        //             $query->where('user_card.AgencyID', $User->id);
        //             $query->where('reward.AgencyID', $User->id);
        //         } else {
        //             $query->where('user_card.UserSysId', $User->id);
        //             $query->where('reward.UserSysId', $User->id);
        //         }
        //     })->where(function ($query) use ($post) {
        //         if (!empty($post['card_number'])) {
        //             $query->where('user_card.card_number', $post['card_number']);
        //         }
        //     })->get();
        // return $responsedata;



        $userInfo = LoyaltyUserCard::select(
            'user_card.*',
            DB::raw('DATE_FORMAT(user_card.created_at, "%d %b, %Y") as createdDate'),
            'users.title',
            'users.fname',
            'users.lname',
            'users.name',
            'users.UserType',
            'users.email',
            'users.mobile'
        )
            ->leftjoin('users', 'users.id', '=', 'user_card.user_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('user_card.AgencyID', $User->id);
                } else {
                    $query->where('user_card.UserSysId', $User->id);
                }
            })
            ->where(function ($query) use ($post) {
                if (!empty($post['card_number'])) {
                    $query->where('user_card.card_number', $post['card_number']);
                }
            })->where('user_card.status', 'active')->first();

        // Then get card and reward info
        $cardRewardInfo = LoyaltyUserCard::select(
            'loyalty_card.expiration_date',
            'loyalty_card.issue_date',
            'loyalty_card.card_type',
            'loyalty_card.program_id',
            'reward.reward_id',
            'reward.reward_name',
            'reward.description',
            'reward.points_required',
            'reward.dealtype',
            'reward.dealvalue',
            'reward.ownervalue',
            'reward.custvalue',
            'reward.rewardtype',
            'reward.ordervalue',
            'reward.rewardvalue',
        )
            ->leftjoin('loyalty_card', 'loyalty_card.card_id', '=', 'user_card.card_id')
            ->join('reward', 'loyalty_card.program_id', '=', 'reward.program_id')
            ->join('stores_mapping', function ($join) use ($post) {
                $join->on('reward.reward_id', '=', 'stores_mapping.reward_id')
                    ->where('stores_mapping.stores_id', '=', $post['store_id'])
                    ->where('stores_mapping.isdelete', '=', 0);
            })
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('reward.AgencyID', $User->id);
                } else {
                    $query->where('reward.UserSysId', $User->id);
                }
            })
            ->where(function ($query) use ($post) {
                if (!empty($post['card_number'])) {
                    $query->where('user_card.card_number', $post['card_number']);
                }
            })
            ->get();
        $AgencyID = $User->UserType == 1 ? $User->id : $User->AgencyID;
        $Store = Store::select('OTPAllowed', 'FaceRecognition', 'noOfPass', 'vendortype')->where('AgencyID', $AgencyID)->where('id', $post['store_id'])->first();
        $response = [
            'user' => $userInfo,
            'Store' => $Store,
            'reward' => $cardRewardInfo
        ];
        return $response;
    }

    public static function getUserCardDetails($User, $post = array())
    {

        $userInfo = LoyaltyUserCard::select(
            'user_card.*',
            DB::raw('DATE_FORMAT(user_card.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_card.program_id',
            DB::raw("
                    CASE 
                        WHEN loyalty_card.program_id = 3 THEN 'LITE'
                        WHEN loyalty_card.program_id = 6 THEN 'PRO'
                        ELSE ''
                    END as program_type
                ")
        )
            ->leftJoin('users', 'users.id', '=', 'user_card.user_id')
            ->leftJoin('loyalty_card', 'loyalty_card.card_id', '=', 'user_card.card_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('user_card.AgencyID', $User->id);
                } else {
                    $query->where('user_card.AgencyID', $User->AgencyID);
                }
            })
            ->where('user_card.status', 'active')
            ->where('user_card.user_id', $User->id)
            ->first();

        return $userInfo;


        // $userInfo = LoyaltyUserCard::select(
        //     'user_card.*',
        //     DB::raw('DATE_FORMAT(user_card.created_at, "%d %b, %Y") as createdDate'),
        // )->leftjoin('users', 'users.id', '=', 'user_card.user_id')
        //     ->where(function ($query) use ($User) {
        //         if ($User->UserType == 1) {
        //             $query->where('user_card.AgencyID', $User->id);
        //         } else {
        //             $query->where('user_card.AgencyID', $User->AgencyID);
        //         }
        //     })->where('user_card.user_id', $User->id)->first();
        // return $userInfo;
    }
    public static function getUserCardDetailsWithReward($User, $post = array())
    {
        $userInfo = LoyaltyUserCard::select(
            'user_card.*',
            'loyalty_card.issue_date',
            'loyalty_card.card_type',
            'loyalty_card.card_id',
            'loyalty_card.program_id',
            'reward.reward_id',
            DB::raw('DATE_FORMAT(user_card.created_at, "%d %b, %Y") as createdDate'),
        )->leftjoin('users', 'users.id', '=', 'user_card.user_id')
            ->leftjoin('loyalty_card', 'loyalty_card.card_id', '=', 'user_card.card_id')
            ->leftjoin('reward', 'reward.AgencyID', '=', 'loyalty_card.AgencyID')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('user_card.AgencyID', $User->id);
                } else {
                    $query->where('user_card.AgencyID', $User->AgencyID);
                }
                if (isset($User->UserCard->program_id) && $User->UserCard->program_id > 0) {
                    $query->where('reward.program_id', $User->UserCard->program_id);
                }
            })->where('user_card.status', 'active')->where('user_card.user_id', $User->id)->first();
        return $userInfo;
    }
}
