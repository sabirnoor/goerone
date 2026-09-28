<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCreditLimit extends Model
{
	protected $table = 'agentcreditlimit';
	protected $primaryKey = 'id';

	protected $fillable = [
		'AgencyID',
		'UserSysId',
		'customer_id',
		'CreditLimit',
		'CurrentOutstanding',
		'EffectiveFrom',
		'EffectiveTo',
		'IsActive',
		'TermsDays',
		'currency',
		'Notes'
	];

	protected $casts = [
		'IsActive' => 'boolean',
		'EffectiveFrom' => 'date',
		'EffectiveTo' => 'date',
		'CreditLimit' => 'decimal:2',
		'CurrentOutstanding' => 'decimal:2',
	];

	public function agency()
	{
		return $this->belongsTo(Users::class, 'AgencyID');
	}

	public function creditTransactions()
	{
		return $this->hasMany(CreditTransaction::class, 'CreditSystemID')->with('customer:id,UserType,name,email,mobile,fname,lname,title,countrycode');
	}

	public function getAvailableCreditAttribute()
	{
		return $this->CreditLimit - $this->CurrentOutstanding;
	}
	public static function getCreditLimitCustomer($user, $customer_id, $perPage, $filterData = [])
	{
		$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
		$transactions = AgentCreditLimit::from('agentcreditlimit as w')->where('w.AgencyID', $AgencyID)
			->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
			->orderBy('w.id', 'desc')
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('w.customer_id', $customer_id);
				}
			})->where('w.IsActive', 1)->paginate($perPage, [
				'w.id',
				'w.IsActive',
				'w.customer_id',
				'w.CreditLimit',
				'w.CurrentOutstanding',
				'w.EffectiveFrom',
				'w.EffectiveTo',
				'w.TermsDays',
				'w.currency',
				'w.Notes',
				'w.created_at',
				'u.UserType',
				'u.name',
				'u.email',
				'u.mobile',
				'u.countrycode'
			]);
		return $transactions;
	}
	public static function getCreditLimitOutstanding($user, $customer_id, $filterData = [])
	{
		$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
		$CurrentOutstanding = AgentCreditLimit::from('agentcreditlimit as w')->where('w.AgencyID', $AgencyID)
			->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
			->orderBy('w.id', 'desc')
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('w.customer_id', $customer_id);
				}
			})->sum('CurrentOutstanding');
		$CreditLimitTotal = AgentCreditLimit::from('agentcreditlimit as w')->where('w.AgencyID', $AgencyID)
			->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
			->orderBy('w.id', 'desc')
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('w.customer_id', $customer_id);
				}
			})->where('w.IsActive', 1)->sum('CreditLimit');
		return ['TotalOutstanding' => $CurrentOutstanding, 'TotalCreditLimit' => $CreditLimitTotal];
	}
}
