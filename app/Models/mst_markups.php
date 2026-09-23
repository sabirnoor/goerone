<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\mst_apikey;

class mst_markups extends Model
{
    protected $table = 'mst_markups';
    protected $fillable = [
        '*',
    ];

    public function cancel()
    {
        return $this->hasMany(MstMarkupsCancellation::class, 'MarketPlaceID');
    }

    public function activeCancel()
    {
        return $this->hasMany(MstMarkupsCancellation::class, 'MarketPlaceID')
            ->where('status', true);
    }

    public static function getApiKeyData($User, $api_source_id = 0, $PlanType = 0)
    {
        $apikey = mst_apikey::select(
            'mst_apikey.id',
            'mst_apikey.api_source_id',
            'mst_apikey.PlanType',
            'mst_apikey.UserSysId',
            'mst_apikey.AgencyID',
            'mst_apikey.EnableRoundTrip',
            'mst_api_source.name as Title'
        )->join('mst_api_source', 'mst_api_source.id', '=', 'mst_apikey.api_source_id')
            // ->where('mst_apikey.UserSysId', $UserSysId) api_source_id
            ->where(function ($query) use ($User) {
                if ($User) {
                    if ($User->UserType == 1) {
                        $query->where('mst_apikey.AgencyID', $User->id);
                    } else {
                        $query->where('mst_apikey.AgencyID', $User->AgencyID);
                    }
                }
            })->where(function ($query) use ($api_source_id) {
                if ($api_source_id > 0) {
                    $query->where('mst_apikey.api_source_id', $api_source_id);
                }
            })->where(function ($query) use ($PlanType) {
                if ($PlanType > 0) {
                    $query->where('mst_apikey.PlanType', $PlanType);
                }
            })
            ->where('mst_apikey.approved', 1)->get()->toArray();

        return $apikey;
    }
    public static function getApiKeyWithoutapproved($User, $api_source_id = 0, $PlanType = 0)
    {
        $apikey = mst_apikey::select(
            'mst_apikey.id',
            'mst_apikey.api_source_id',
            'mst_apikey.PlanType',
            'mst_apikey.UserSysId',
            'mst_apikey.AgencyID',
            'mst_apikey.EnableRoundTrip',
            'mst_api_source.name as Title'
        )->join('mst_api_source', 'mst_api_source.id', '=', 'mst_apikey.api_source_id')
            // ->where('mst_apikey.UserSysId', $UserSysId) api_source_id
            ->where(function ($query) use ($User) {
                if ($User) {
                    if ($User->UserType == 1) {
                        $query->where('mst_apikey.AgencyID', $User->id);
                    } else {
                        $query->where('mst_apikey.AgencyID', $User->AgencyID);
                    }
                }
            })->where(function ($query) use ($api_source_id) {
                if ($api_source_id > 0) {
                    $query->where('mst_apikey.api_source_id', $api_source_id);
                }
            })->where(function ($query) use ($PlanType) {
                if ($PlanType > 0) {
                    $query->where('mst_apikey.PlanType', $PlanType);
                }
            })->first();

        return $apikey;
    }
    public static function getApiKeyDataAll($User)
    {
        $apikey = mst_apikey::select(
            'mst_apikey.id',
            'mst_apikey.api_source_id',
            'mst_apikey.PlanType',
            'mst_apikey.UserSysId',
            'mst_apikey.AgencyID',
            'mst_apikey.EnableRoundTrip',
            'mst_api_source.name as Title'
        )->join('mst_api_source', 'mst_api_source.id', '=', 'mst_apikey.api_source_id')
            // ->where('mst_apikey.UserSysId', $UserSysId)
            ->where(function ($query) use ($User) {
                if ($User) {
                    if ($User->UserType == 1) {
                        $query->where('mst_apikey.AgencyID', $User->id);
                    } else {
                        $query->where('mst_apikey.AgencyID', $User->AgencyID);
                    }
                }
            })->get()->toArray();

        return $apikey;
    }
    public static function AgencyMarketPlaceList($UserSysId, $User = null)
    {
        $results = mst_markups::select('*')->withExists(['cancel as has_cancel' => function ($q) {
            $q->where('status', true);
        }])
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m-%d %H:%i") as createdat')
            ->selectRaw('DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i") as updatedat')
            ->where(array('parent_id' => 0))->where(function ($query) use ($User, $UserSysId) {
                if ($User) {
                    if ($User->UserType == 1) {
                        $query->where('AgencyID', $User->id);
                    } else {
                        $query->where('AgencyID', $User->AgencyID);
                    }
                } else {
                    $query->where('UserSysId', $UserSysId);
                }
            })->orderBy('id', 'ASC')->get();
        if (!empty($results)) {
            $results = $results->toArray();
        }
        return $results;
    }
    public static function AgencyMarketPlaceDetails($UserSysId, $id)
    {
        $results = mst_markups::select('*')
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m-%d %H:%i") as createdat')
            ->selectRaw('DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i") as updatedat')
            ->where(array('parent_id' => $id, 'UserSysId' => $UserSysId))->orderBy('id', 'ASC')->get();
        if (!empty($results)) {
            $results = $results->toArray();
        }
        return $results;
    }
    public static function MasterMarketPlaceDetails($UserSysId, $id)
    {
        $results = mst_markups::select(
            'mst_markups.id',
            'mst_markups.title',
            'mst_markups.status',
            'mst_markups.approved',
            'mst_markups.is_flexi',
            'mst_markups.whatsapp_ticket',
            'mst_markups.sms_ticket',
            'mst_markups_flexi.id as flexid',
            'mst_markups_flexi.UserSysId',
            'mst_markups.upgrade_vip',
            'mst_markups_flexi.AgencyID',
            'mst_markups_flexi.deposit_required',
            'mst_markups_flexi.processing_fee',
            'mst_markups_flexi.activebefore',
            'mst_markups_flexi.activeto',
            'mst_markups_flexi.cancelbefore',
            'mst_markups_flexi.DAdultPrice',
            'mst_markups_flexi.DChildPrice',
            'mst_markups_flexi.DInfantPrice',
            'mst_markups_flexi.IAdultPrice',
            'mst_markups_flexi.IChildPrice',
            'mst_markups_flexi.IInfantPrice'
        )
            ->selectRaw('DATE_FORMAT(mst_markups.created_at, "%Y-%m-%d %H:%i") as createdat')
            ->selectRaw('DATE_FORMAT(mst_markups.updated_at, "%Y-%m-%d %H:%i") as updatedat')
            ->leftjoin('mst_markups_flexi', 'mst_markups_flexi.mst_markups_id', '=', 'mst_markups.id')
            ->where(array('mst_markups.id' => $id, 'mst_markups.UserSysId' => $UserSysId))->orderBy('mst_markups.created_at', 'DESC')->first();
        if (!empty($results)) {
            $results = $results->toArray();
        }
        return $results;
    }
    public static function getMarkup($users, $api_source_id, $type, $MarketPlaceID)
    {
        $results = mst_markups::select('id', 'title', 'status', 'commission', 'markuptype', 'markup', 'seatmarkup', 'rewardvalue', 'type', 'parent_id', 'is_flexi', 'Defaultb2b')
            ->where(array(
                'api_source_id' => $api_source_id,
                'type' => $type,
                // 'UserSysId' => $UserSysId,
                'status' => 'Activate',
                'approved' => 1,
            ))->where(function ($query) use ($MarketPlaceID) {
                if ($MarketPlaceID > 0) {
                    $query->where('parent_id', $MarketPlaceID);
                } else {
                    $query->where('Defaultb2b', 1);
                }
            })->where(
                function ($query) use ($users) {
                    if ($users->UserType == 1) {
                        return $query->where('AgencyID', $users->id);
                    } else {
                        return $query->where('AgencyID', $users->AgencyID);
                    }
                }
            )->orderBy('created_at', 'DESC')->first();

        if (!empty($results)) {
            $results = $results->toArray();
        }
        return $results;
    }
    public static function getMarkupForFlexi($users, $api_source_id, $type, $MarketPlaceID, $taxpercentage, $VIPAgency)
    {
        $results = mst_markups::select('id', 'title', 'status', 'commission', 'markuptype', 'markup', 'seatmarkup', 'rewardvalue', 'type', 'parent_id', 'is_flexi')
            ->where(array(
                'api_source_id' => $api_source_id,
                'type' => $type,
                // 'UserSysId' => $UserSysId,
                'status' => 'Activate',
                'approved' => 1,
                'is_flexi' => 1,
                'upgrade_vip' => 2,
            ))->where(function ($query) use ($MarketPlaceID) {
                if ($MarketPlaceID > 0) {
                    $query->where('parent_id', $MarketPlaceID);
                } else {
                    $query->where('is_flexi', 1);
                }
            })->where(
                function ($query) use ($users) {
                    if ($users->UserType == 1) {
                        return $query->where('AgencyID', $users->id);
                    } else {
                        return $query->where('AgencyID', $users->AgencyID);
                    }
                }
            )->orderBy('created_at', 'DESC')->first();

        $getMarkupTaxes = [];
        if (!empty($results)) {
            $results = $results->toArray();
            $commission = isset($results['commission']) ? $results['commission'] : 0;
            $MarkUpType = isset($results['markuptype']) ? $results['markuptype'] : 0;
            $MarkUpValue = isset($results['markup']) ? $results['markup'] : 0;
            $rewardvalue = isset($results['rewardvalue']) ? $results['rewardvalue'] : 0;
            $seatmarkup = isset($results['seatmarkup']) ? $results['seatmarkup'] : 0;
            $mst_markups_id = isset($results['parent_id']) ? $results['parent_id'] : 0;
            $is_flexi = isset($results['is_flexi']) ? $results['is_flexi'] : 0;
            $getMarkupTaxes = [
                'mst_markups_id' => $mst_markups_id,
                'is_flexi' => $is_flexi,
                'VIPAgency' => $VIPAgency,
                'TaxPercentage' => $taxpercentage,
                'MarkUpType' => $MarkUpType,
                'MarkUpValue' => $MarkUpValue,
                'commission' => $commission,
                'rewardvalue' => $rewardvalue,
                'seatmarkup' => $seatmarkup,
            ];
        }
        return $getMarkupTaxes;
    }

    public static function getTicketFlags($MarketPlaceID, $User)
    {
        $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
        return mst_markups::select('id', 'whatsapp_ticket', 'sms_ticket')->where('AgencyID', $AgencyID)
            ->where(function ($query) use ($MarketPlaceID) {
                if ($MarketPlaceID > 0) {
                    $query->where('parent_id', $MarketPlaceID);
                } else {
                    $query->where('Defaultb2b', 1);
                }
            })
            ->first();
    }
}
