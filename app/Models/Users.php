<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Users extends Model
{
    protected $table = 'users';
    protected $fillable = [
        '*',
    ];
    protected $hidden = ['password'];
    public function bookinglimit()
    {
        return $this->hasMany(
            BookingLimit::class,
            'Staff_id',
            'id'
        );
    }
    public function apikey()
    {
        return $this->hasMany(
            mst_apikey::class,
            'AgencyID',
            'id'
        );
    }
    public static function getAgencyStaff($User)
    {
        $result = Users::with('bookinglimit')->select(
            'users.*',
            'users.created_at as createdAt',
            'incorporation_details.tax_number',
            'incorporation_details.logo',
            'incorporation_details.address',
            'incorporation_details.address1',
            'incorporation_details.country',
            'incorporation_details.city',
            'incorporation_details.pincode',
            'incorporation_details.BookingAllowed',
            'incorporation_details.LedgerDowAllowed',
            'incorporation_details.TickerDowAllowed',
            'incorporation_details.addressproof',
            'incorporation_details.onboarding_process',
            'incorporation_details.mobile as AgentMobile',
            'incorporation_details.email as AgentEmail',
            'mst_lead_source.name as leadsource',
        )
            ->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
            ->leftjoin('mst_lead_source', 'mst_lead_source.id', '=', 'incorporation_details.leadsource')
            ->where('users.UserType', '=', 4)
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('users.AgencyID', $User->id);
                } else {
                    $query->where('users.UserSysId', $User->id);
                }
            })->orderBy('created_at', 'DESC')->get()->toArray();

        return $result;
    }

    public static function getSupplierUsers($User)
    {
        $result = Users::with('bookinglimit')->select(
            'users.*',
            'users.created_at as createdAt',
            'incorporation_details.tax_number',
            'incorporation_details.logo',
            'incorporation_details.address',
            'incorporation_details.address1',
            'incorporation_details.country',
            'incorporation_details.city',
            'incorporation_details.pincode',
            'incorporation_details.BookingAllowed',
            'incorporation_details.LedgerDowAllowed',
            'incorporation_details.TickerDowAllowed',
            'incorporation_details.addressproof',
            'incorporation_details.onboarding_process',
            'incorporation_details.mobile as AgentMobile',
            'incorporation_details.email as AgentEmail',
            'mst_lead_source.name as leadsource',
        )
            ->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
            ->leftjoin('mst_lead_source', 'mst_lead_source.id', '=', 'incorporation_details.leadsource')
            ->where('users.UserType', '=', 8)
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('users.AgencyID', $User->id);
                } else {
                    $query->where('users.UserSysId', $User->id);
                }
            })->orderBy('created_at', 'DESC')->get()->toArray();

        return $result;
    }

    public static function getAllAgencyStaff($User)
    {
        $result = Users::select(
            'users.*',
            'users.created_at as createdAt',
            'incorporation_details.tax_number',
            'incorporation_details.logo',
            'incorporation_details.address',
            'incorporation_details.address1',
            'incorporation_details.country',
            'incorporation_details.city',
            'incorporation_details.pincode',
            'incorporation_details.addressproof',
            'incorporation_details.onboarding_process',
            'incorporation_details.mobile as AgentMobile',
            'incorporation_details.email as AgentEmail',
            'mst_lead_source.name as leadsource',
        )
            ->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
            ->leftjoin('mst_lead_source', 'mst_lead_source.id', '=', 'incorporation_details.leadsource')
            ->where('users.UserType', '=', 4)
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('users.AgencyID', $User->id);
                } else {
                    $query->where('users.AgencyID', $User->AgencyID);
                }
            })->get()->toArray();

        return $result;
    }
    public static function getSuggestCustomer($User)
    {
        $result = Users::select(
            'users.id',
            'users.AgencyID',
            'users.UserType',
            'users.name',
            'users.mobile',
            'users.email',
            'incorporation_details.tax_number',
            'incorporation_details.mst_state_id',
            'incorporation_details.logo',
            'incorporation_details.address',
            'incorporation_details.address1',
            'incorporation_details.country',
            'incorporation_details.city',
            'incorporation_details.pincode',
            'incorporation_details.addressproof',
            'incorporation_details.onboarding_process',
            'incorporation_details.mobile as AgentMobile',
            'incorporation_details.email as AgentEmail',
            'users.taxID',
            'users.CurrencyID',
            'users.title',
            'users.fname',
            'users.lname',
            'mst_currency.name as Currency',
        )->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
            ->leftjoin('mst_currency', 'mst_currency.id', '=', 'users.CurrencyID')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('users.AgencyID', $User->id);
                } else {
                    $query->where('users.AgencyID', $User->AgencyID);
                }
            })->where(function ($query) {  // This ensures both conditions are grouped
                $query->where('users.UserType', 0)
                    ->orWhere('users.UserType', 2);
            })->orderBy('users.id', 'DESC')->limit(25)->get()->toArray();

        return $result;
    }
    public static function getCustomerDetails($Userdetails, $User)
    {
        // echo $Userdetails->id;
        // die;
        $result = Users::select(
            'users.*',
            'incorporation_details.tax_number',
            'incorporation_details.mst_state_id',
            'incorporation_details.logo',
            'incorporation_details.address',
            'incorporation_details.address1',
            'incorporation_details.country',
            'incorporation_details.city',
            'incorporation_details.pincode',
            'incorporation_details.addressproof',
            'incorporation_details.agencyName',
            'incorporation_details.onboarding_process',
            'incorporation_details.mobile as AgentMobile',
            'incorporation_details.email as AgentEmail',
            'mst_currency.name as Currency',
        )->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
            ->leftjoin('mst_currency', 'mst_currency.id', '=', 'users.CurrencyID')
            ->where('users.id', '=', $Userdetails->id)->where('users.active', '=', 1)
            ->where('users.UserType', '=', $Userdetails->UserType)
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('users.AgencyID', $User->id);
                } else {
                    $query->where('users.AgencyID', $User->AgencyID);
                }
            })->first()->toArray();

        return $result;
    }
    public static function getUserDetails($User)
    {
        $result = Users::select(
            'users.*',
            'incorporation_details.tax_number',
            'incorporation_details.mst_state_id',
            'incorporation_details.logo',
            'incorporation_details.address',
            'incorporation_details.address1',
            'incorporation_details.country',
            'incorporation_details.city',
            'incorporation_details.pincode',
            'incorporation_details.addressproof',
            'incorporation_details.agencyName',
            'incorporation_details.onboarding_process',
            'incorporation_details.mobile as AgentMobile',
            'incorporation_details.email as AgentEmail',
            'mst_currency.name as Currency',
        )->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
            ->leftjoin('mst_currency', 'mst_currency.id', '=', 'users.CurrencyID')
            ->where('users.id', '=', $User->id)->where('users.active', '=', 1)
            ->where('users.UserType', '=', $User->UserType)
            ->where('users.AgencyID', $User->AgencyID)
            ->first()->toArray();

        return $result;
    }
    // public static function getApiKeyData($UserSysId)
    // {
    //     $apikey = mst_apikey::select('mst_apikey.*', 'mst_api_source.name as Title')
    //         ->join('mst_api_source', 'mst_api_source.id', '=', 'mst_apikey.api_source_id')
    //         ->where('mst_apikey.UserSysId',$UserSysId)
    //         ->where('mst_apikey.approved',1)->get()->toArray();

    //     return $apikey;
    // }


    public static function getAgencyDetail($User, int $config = 0)
    {
        if ($config == 1) {
            $result = Users::select(
                'users.*',
                'users.created_at as createdAt',
                'incorporation_details.tax_number',
                'incorporation_details.logo',
                'incorporation_details.address',
                'incorporation_details.address1',
                'incorporation_details.country',
                'incorporation_details.city',
                'incorporation_details.pincode',
                'incorporation_details.addressproof',
                'incorporation_details.onboarding_process',
                'incorporation_details.mobile as AgentMobile',
                'incorporation_details.email as AgentEmail',
                'incorporation_details.agencyName',
                'incorporation_details.tax_number',
                'incorporation_details.VIPAgency',
                'incorporation_details.IsProd',
                'incorporation_details.VIPPaymentStatus',
                'incorporation_details.api_key',
                'incorporation_details.META_APP_SECRET',
                'incorporation_details.META_APP_ID',
                'incorporation_details.BookingAllowed',
                'incorporation_details.franchise_allowed',
                'incorporation_details.offline_allowed',
                'incorporation_details.holidays_allowed',
                'incorporation_details.hotel_allowed',
                'incorporation_details.flight_allowed',
                'incorporation_details.disruption_allowed',
                'incorporation_details.bnpl_allowed',
                'incorporation_details.passport_allowed',
                'incorporation_details.visa_allowed',
                'incorporation_details.bus_allowed',
                'incorporation_details.train_allowed',
                'mst_lead_source.name as leadsource',
            )
                ->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
                ->leftjoin('mst_lead_source', 'mst_lead_source.id', '=', 'incorporation_details.leadsource')
                ->where('users.UserType', '=', 1)
                ->where('users.id', $User)
                ->first();
        } else {
            $result = Users::with('apikey')->select(
                'users.*',
                'users.created_at as createdAt',
                'incorporation_details.tax_number',
                'incorporation_details.logo',
                'incorporation_details.address',
                'incorporation_details.address1',
                'incorporation_details.country',
                'incorporation_details.city',
                'incorporation_details.pincode',
                'incorporation_details.addressproof',
                'incorporation_details.onboarding_process',
                'incorporation_details.mobile as AgentMobile',
                'incorporation_details.email as AgentEmail',
                'incorporation_details.agencyName',
                'incorporation_details.tax_number',
                'incorporation_details.VIPAgency',
                'incorporation_details.IsProd',
                'incorporation_details.VIPPaymentStatus',
                'incorporation_details.api_key',
                'incorporation_details.META_APP_SECRET',
                'incorporation_details.META_APP_ID',
                'incorporation_details.BookingAllowed',
                'incorporation_details.franchise_allowed',
                'incorporation_details.offline_allowed',
                'incorporation_details.holidays_allowed',
                'incorporation_details.hotel_allowed',
                'incorporation_details.flight_allowed',
                'incorporation_details.disruption_allowed',
                'incorporation_details.bnpl_allowed',
                'incorporation_details.passport_allowed',
                'incorporation_details.visa_allowed',
                'incorporation_details.bus_allowed',
                'incorporation_details.train_allowed',
                'mst_lead_source.name as leadsource',
            )
                ->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
                ->leftjoin('mst_lead_source', 'mst_lead_source.id', '=', 'incorporation_details.leadsource')
                ->where('users.UserType', '=', 1)
                ->where('users.id', $User)
                ->first();
        }


        return $result;
    }

    public static function getCustomerWhatsapp($User, $post)
    {
        $result = Users::select(
            'users.id',
            'users.AgencyID',
            'users.UserType',
            'users.name',
            'users.mobile',
            'users.email',
            'users.title',
            'users.fname',
            'users.lname',
            'incorporation_details.address',
            'incorporation_details.address1',
            'incorporation_details.country',
            'incorporation_details.city',
            'incorporation_details.pincode',
            'mst_currency.name as Currency',
        )->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
            ->leftjoin('mst_currency', 'mst_currency.id', '=', 'users.CurrencyID')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('users.AgencyID', $User->id);
                } else {
                    $query->where('users.AgencyID', $User->AgencyID);
                }
            })->where(function ($query) use ($post) {
                if (isset($post['email']) && $post['email']) {
                    $query->where('users.email', $post['email']);
                }
            })->where(function ($query) {  // This ensures both conditions are grouped
                $query->where('users.UserType', 0)
                    ->orWhere('users.UserType', 2)->orWhere('users.UserType', 6)->orWhere('users.UserType', 7);
            })->first();

        return $result;
    }
}
