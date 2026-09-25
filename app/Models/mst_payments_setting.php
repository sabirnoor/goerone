<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class mst_payments_setting extends Model
{
	protected $table = 'mst_payments_setting';
	protected $fillable = ['*'];


	public static function PaymentKeyMaskData($User)
	{
		$PaymentKeyData = mst_payments_setting::where(function ($query) use ($User) {
			if ($User->UserType == 1) {
				$query->where('AgencyID', $User->id);
			} else {
				$query->where('AgencyID', $User->AgencyID);
			}
		})->where('isdefault', 1)->first();
		$data = [];
		if (!empty($PaymentKeyData)) {
			$DataArray = $PaymentKeyData->toArray();
			$data[$DataArray['ActivePG']] = $DataArray;
			$data[$DataArray['ActivePG']]['KeyID'] = maskApiKey($DataArray['KeyID']);
			$data[$DataArray['ActivePG']]['KeySecret'] = maskApiKey($DataArray['KeySecret']);
			return $data;
		} else {
			return $PaymentKeyData;
		}
	}
	public static function PGCredentialData($User)
	{
		$AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
		$PaymentKeyData = mst_payments_setting::where('AgencyID', $AgencyID)
			->where('isdefault', 1)
			->first();
		return $PaymentKeyData ?: [];
	}
	public static function PGCredentialAll($User)
	{
		$AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
		$PaymentKeyData = mst_payments_setting::where('AgencyID', $AgencyID)->get();
		return $PaymentKeyData ?: [];
	}
}
