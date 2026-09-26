<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class mst_apikey extends Model
{
    protected $table = 'mst_apikey';
    protected $fillable = [
        '*',
    ];

    public static function getApiKeyData($User,$isAdmin=false)
    {
        $apikey = mst_apikey::select('mst_apikey.*', 'mst_api_source.name as Title')
            ->join('mst_api_source', 'mst_api_source.id', '=', 'mst_apikey.api_source_id')
            // ->where('mst_apikey.UserSysId', $UserSysId)
            ->where(function ($query) use ($User) {
                if ($User) {
                    if ($User->UserType == 1) {
                        $query->where('mst_apikey.AgencyID', $User->id);
                    } else {
                        $query->where('mst_apikey.UserSysId', $User->id);
                    }
                }
            }) ->where(function ($query) use ($isAdmin) {
                    if ($isAdmin) {
                        $query->whereIn('mst_apikey.approved', [1,0]);
                    } else {
                        $query->where('mst_apikey.approved', 1);
                    }
                
            })->get()->toArray();

        return $apikey;
    }
    public static function getApiKeyDataSetting($User)
    {
        $apikey = mst_apikey::select('mst_apikey.*', 'mst_api_source.name as Title')
            ->join('mst_api_source', 'mst_api_source.id', '=', 'mst_apikey.api_source_id')
            ->where(function ($query) use ($User) {
                if ($User) {
                    if ($User->UserType == 1) {
                        $query->where('mst_apikey.AgencyID', $User->id);
                    } else {
                        $query->where('mst_apikey.UserSysId', $User->id);
                    }
                }
            })->get();
        if ($apikey) {
            $apikey = $apikey->toArray();
        }
        return $apikey;
    }
}
