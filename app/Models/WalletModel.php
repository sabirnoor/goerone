<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Services\RewardService;
use App\Rules\BelongsToAgency;
use App\Services\AgentBnplService;

class WalletModel extends Model
{
	protected $table = 'wallet';
	protected $fillable = [
		'id',
		'AgencyID',
		'CreditSystemID',
		'UserSysId',
		'customer_id',
		'PType',
		'amount',
		'RefrenceNo',
		'BalanceAmount',
		'Remark',
		'PlanType',
		'approved',
		'TrxId',
		'PaymentGUID',
		'PaymentMode',
		'TDS',
		'Notes',
		'is_profit',
		'credit_refund',
		'PaymentDate',
		'DueDate',
		'IsCreditPayment'
	];
	protected $primaryKey = 'id';
	protected $hidden = ['updated_at'];

	protected $casts = [
		'IsCreditPayment' => 'boolean',
		'PaymentDate' => 'date',
		'DueDate' => 'date',
		'amount' => 'decimal:2',
		'BalanceAmount' => 'decimal:2',
		'TDS' => 'decimal:2',
		// 'amount' => 'float',
		// 'BalanceAmount' => 'float',
		// 'TDS' => 'float',
	];

	public function creditTransaction()
	{
		return $this->belongsTo(CreditTransaction::class, 'CreditSystemID');
	}

	public function agency()
	{
		return $this->belongsTo(Users::class, 'AgencyID');
	}

	public static function refreshBalance($user_id)
	{
		if ($user_id) {
			$available = WalletModel::selectRaw(
				"SUM(CASE WHEN PType = 'CR' THEN amount ELSE 0 END) AS TotalCredit, " .
					"SUM(CASE WHEN PType = 'DR' THEN amount ELSE 0 END) AS TotalDebit, AgencyID"
			)
				->groupBy('AgencyID')
				->where(array('AgencyID' => $user_id, 'approved' => '1'))->first();
			if ($available) {
				$TotalCredit = !empty($available) ? $available->TotalCredit : 0;
				$TotalDebit = !empty($available) ? $available->TotalDebit : 0;
				$AgencyID = !empty($available) ? $available->AgencyID : 0;
				$closingBalance = ($TotalCredit - $TotalDebit);
				$data = array('status' => true, 'httpStatus' => 200, 'TotalCredit' => $TotalCredit, 'TotalDebit' => $TotalDebit, 'closingBalance' => $closingBalance, 'AgencyID' => $AgencyID, 'symbal' => '₹', 'message' => 'Success');
				return ($data);
			}
		} else {
			$data = array('status' => false, 'httpStatus' => 2002, 'message' => 'Invalid request');
			return ($data);
		}
	}

	public static function getBalanceByCustomer($customer_id, $AgencyID = 0)
	{
		if ($customer_id) {
			$available = WalletModel::selectRaw(
				"SUM(CASE WHEN PType = 'CR' THEN amount ELSE 0 END) AS TotalCredit, " .
					"SUM(CASE WHEN PType = 'DR' THEN amount ELSE 0 END) AS TotalDebit, customer_id"
			)->groupBy('customer_id')
				->whereNull('CreditSystemID')->where('IsCreditPayment', false)
				->where(function ($query) use ($AgencyID) {
					if (!empty($AgencyID)) {
						$query->where('AgencyID', $AgencyID);
					}
				})
				->where(array('customer_id' => $customer_id, 'approved' => '1'))->first();
			if ($available) {
				$TotalCredit = !empty($available) ? $available->TotalCredit : 0;
				$TotalDebit = !empty($available) ? $available->TotalDebit : 0;
				$AgencyID = !empty($available) ? $available->AgencyID : 0;
				$closingBalance = ($TotalCredit - $TotalDebit);
				$data = array('status' => true, 'httpStatus' => 200, 'TotalCredit' => $TotalCredit, 'TotalDebit' => $TotalDebit, 'closingBalance' => $closingBalance, 'customer_id' => $customer_id, 'AgencyID' => $AgencyID, 'symbal' => '₹', 'message' => 'Success');
				return ($data);
			} else {
				$data = array('status' => true, 'httpStatus' => 200, 'TotalCredit' => 0, 'TotalDebit' => 0, 'closingBalance' => 0, 'customer_id' => $customer_id, 'AgencyID' => $customer_id, 'symbal' => '₹', 'message' => 'Success');
				return ($data);
			}
		} else {
			$data = array('status' => false, 'httpStatus' => 2002, 'message' => 'Invalid request');
			return ($data);
		}
	}
	public static function getLedger($customer_id, $AgencyID = NULL)
	{
		$DataList = [];
		if ($customer_id) {
			$DataList = WalletModel::select('wallet.*', 'users.name as CustomerName', DB::raw('DATE_FORMAT(wallet.created_at, "%d %b, %Y %H:%i") as createdDate'))->where('customer_id', $customer_id)
				->where(function ($query) use ($AgencyID) {
					if (!empty($AgencyID)) {
						$query->where('wallet.AgencyID', $AgencyID);
					}
				})
				->leftJoin('users', 'users.id', '=', 'wallet.customer_id')
				->whereNull('CreditSystemID')->where('IsCreditPayment', false)
				->where('users.WalletStatus', 1)
				->where('approved', 1)
				->orderBy('id', 'DESC')
				->get();
			if ($DataList) {
				$DataList = $DataList->toArray();
			} else {
				$DataList = [];
			}
		} else {
			$DataList = [];
		}
		return $DataList;
	}
	public static function DebitFromWallet($data, $AgencyID = NULL)
	{
		if ($data) {
			try {
				$BalanceByCustomer = self::getBalanceByCustomer($data['customer_id']);
				$closingBalance = isset($BalanceByCustomer['closingBalance']) ? (float)$BalanceByCustomer['closingBalance'] : 0;
				$BalanceAmount = ($closingBalance - (float)$data['amount']);

				if ($BalanceAmount >= 0) {
					User::where('id', $data['customer_id'])->update(['WalletBalance' => ($BalanceAmount)]);
					$walletInsert = array(
						'customer_id' => $data['customer_id'],
						'AgencyID' => $data['AgencyID'],
						'PType' => 'DR',
						'amount' => (float)$data['amount'],
						'RefrenceNo' => $data['RefrenceNo'],
						'TrxID' => $data['TrxID'],
						'PaymentMode' => $data['PaymentMode'],
						'BalanceAmount' => $BalanceAmount,
						'Remark' => !empty($data['Remark']) ? $data['Remark'] : 'Booking',
						'approved' => 1,
						'PlanType' => $data['PlanType'],
						'created_at' => date('Y-m-d H:i:s'),
						'updated_at' => date('Y-m-d H:i:s'),
					);

					$lastInsertId = WalletModel::insertGetId($walletInsert);
					return [
						'status' => [
							'success' => true,
							'httpStatus' => 200,
						],
						'InsertId' => $lastInsertId,
						'message' => 'success',
					];
				} else {
					return [
						'status' => [
							'success' => false,
							'httpStatus' => 201,
						],
						'message' => 'Unbale to debit from wallet. Please contact our customer support',
					];
				}
			} catch (\Throwable $th) {
				return [
					'status' => [
						'success' => false,
						'httpStatus' => 201,
					],
					'message' => $th->getMessage(),
				];
			}
		} else {
			return [
				'status' => [
					'success' => false,
					'httpStatus' => 404,
				],
				'message' => 'invalid request',
			];
		}
		return [
			'status' => [
				'success' => false,
				'httpStatus' => 404,
			],
			'message' => 'false',
		];
	}
	public static function CreditWallet($data, $AgencyID = NULL)
	{
		if ($data) {
			try {
				$BalanceByCustomer = self::getBalanceByCustomer($data['customer_id']);
				$closingBalance = isset($BalanceByCustomer['closingBalance']) ? (float)$BalanceByCustomer['closingBalance'] : 0;
				$BalanceAmount = ($closingBalance + (float)$data['amount']);
				$amount = (float)$data['amount'];
				$checkduplicate = WalletModel::where(array('customer_id' => $data['customer_id'], 'RefrenceNo' => $data['RefrenceNo'], 'TrxID' => $data['TrxID'], 'PType' => 'CR', 'approved' => '1'))->first();
				if ($amount >= 0 && empty($checkduplicate)) {
					User::where('id', $data['customer_id'])->update(['WalletBalance' => ($BalanceAmount)]);
					$walletInsert = array(
						'customer_id' => $data['customer_id'],
						'AgencyID' => $data['AgencyID'],
						'PType' => 'CR',
						'amount' => (float)$data['amount'],
						'RefrenceNo' => $data['RefrenceNo'],
						'TrxID' => $data['TrxID'],
						'PaymentMode' => $data['PaymentMode'],
						'BalanceAmount' => $BalanceAmount,
						'Remark' => !empty($data['Remark']) ? $data['Remark'] : 'Topup',
						'TDS' => (isset($data['TDS']) && !empty($data['TDS'])) ? $data['TDS'] : 0,
						'approved' => 1,
						'PlanType' => $data['PlanType'],
						'created_at' => date('Y-m-d H:i:s'),
						'updated_at' => date('Y-m-d H:i:s'),
					);

					$lastInsertId = WalletModel::insertGetId($walletInsert);
					return [
						'status' => [
							'success' => true,
							'httpStatus' => 200,
						],
						'InsertId' => $lastInsertId,
						'message' => 'success',
					];
				} else {
					return [
						'status' => [
							'success' => false,
							'httpStatus' => 201,
						],
						'message' => 'Unbale to credit from wallet. Please contact our customer support',
					];
				}
			} catch (\Throwable $th) {
				return [
					'status' => [
						'success' => false,
						'httpStatus' => 201,
					],
					'message' => $th->getMessage(),
				];
			}
		} else {
			return [
				'status' => [
					'success' => false,
					'httpStatus' => 404,
				],
				'message' => 'invalid request',
			];
		}
		return [
			'status' => [
				'success' => false,
				'httpStatus' => 404,
			],
			'message' => 'false',
		];
	}

	public static function WalletHistory($user, $customer_id, $perPage, $filterData = [])
	{
		$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
		$loginUserType = (int) ($user->UserType ?? 4);
		// $transactions = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)->leftJoin('users', 'users.id', '=', 'wallet.customer_id')
		// 	->whereNull('CreditSystemID')
		// 	->where('IsCreditPayment', false)
		// 	->orderBy('created_at', 'desc')
		// 	->get([
		// 		'id',
		// 		'PType',
		// 		'amount',
		// 		'BalanceAmount',
		// 		'RefrenceNo',
		// 		'PaymentMode',
		// 		'PlanType',
		// 		'Remark',
		// 		'PaymentDate',
		// 		'created_at',
		// 		'users.name'
		// 	]);


		if (isset($filterData['ReferenceNo']) && !empty($filterData['ReferenceNo'])) {
			$loginUserType = 1;
		}

		$transactions = WalletModel::from('wallet as w')->where('w.AgencyID', $AgencyID)->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
			// ->whereNull('w.CreditSystemID')
			// ->where('w.IsCreditPayment', false)
			->where(function ($query) {
				$query->where(function ($sub) {
					$sub->where('w.credit_refund', 1)
						->where('w.IsCreditPayment', true);
				})->orWhere(function ($sub) {
					$sub->where('w.credit_refund', '!=', 1)
						->where('w.IsCreditPayment', false);
				});
			})
			->where('w.is_profit', false)
			->orderBy('w.id', 'desc')
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('w.customer_id', $customer_id);
				}
			})->where(function ($query) use ($filterData) {
				$FromDate = isset($filterData['TransactionFromDate']) ? date('Y-m-d', strtotime($filterData['TransactionFromDate'])) : '';
				$ToDate = isset($filterData['TransactionToDate']) ? date('Y-m-d', strtotime($filterData['TransactionToDate'])) : '';
				// if (isset($filterData['ReferenceNo']) && !empty($filterData['ReferenceNo'])) {
				// 	$query->where('w.RefrenceNo', $filterData['ReferenceNo']);
				// }
				if (isset($filterData['ReferenceNo']) && !empty($filterData['ReferenceNo'])) {
					$query->where(function ($q) use ($filterData) {
						$q->where('w.RefrenceNo', $filterData['ReferenceNo'])
							->orWhere('w.Remark', 'like', '%' . $filterData['ReferenceNo'] . '%');
					});
				}
				if ($FromDate && $ToDate) {
					return $query->whereBetween('w.created_at', [$FromDate . " 00:00:00", $ToDate . " 23:59:59"]);
				}
			})->paginate($perPage, [
				'w.id',
				'w.PType',
				'w.amount',
				'w.BalanceAmount',
				'w.RefrenceNo',
				'w.PaymentMode',
				'w.PlanType',
				'w.Remark',
				'w.Notes',
				'w.PaymentDate',
				'w.created_at',
				'w.currency',
				'u.name',
				// 'u.email',
				DB::raw("
				CASE 
					WHEN {$loginUserType} = 1 OR u.email IS NULL OR LENGTH(u.email) <= 4
					THEN u.email
					ELSE CONCAT(
						LEFT(u.email, 2),
						REPEAT('*', LENGTH(u.email) - 4),
						RIGHT(u.email, 2)
					)
				END as email
			"),
				DB::raw("
				CASE 
					WHEN {$loginUserType} = 1 OR u.mobile IS NULL OR LENGTH(u.mobile) <= 4
					THEN u.mobile
					ELSE CONCAT(
						LEFT(u.mobile, 2),
						REPEAT('*', LENGTH(u.mobile) - 4),
						RIGHT(u.mobile, 2)
					)
				END as mobile
			"),
				// 'u.mobile',
				'u.countrycode'
			]);
		$creditLimit = AgentCreditLimit::where('agentcreditlimit.AgencyID', $AgencyID)
			->join('users as u', 'u.id', '=', 'agentcreditlimit.customer_id')
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('agentcreditlimit.customer_id', $customer_id);
				}
			})->where(function ($query) use ($user) {
				if ($user->UserType == 2) {
					$query->where('u.CreditLimitStatus', true);
				}
			})->where('agentcreditlimit.IsActive', true)->first();

		$totalOutstanding = AgentCreditLimit::where('AgencyID', $AgencyID)
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('customer_id', $customer_id);
				}
			})->sum('CurrentOutstanding');
		$availableCredit = $creditLimit ? ($creditLimit->CreditLimit) : 0;
		$totalProfit = WalletModel::where('AgencyID', $AgencyID)
			->where(function ($query) use ($customer_id, $user) {
				if ($customer_id > 0) {
					$query->where('customer_id', $customer_id);
				} else {
					$query->where('AgencyID', $user->id);
				}
			})->whereNull('CreditSystemID')->where('IsCreditPayment', false)->where('is_profit', true)->sum('amount');

		return [
			'status' => [
				'success' => true,
				'httpStatus' => 200,
			],
			'wallet_balance' => $transactions->first()->BalanceAmount ?? 0,
			'current_balance' => $transactions->first()->BalanceAmount ?? 0 + $availableCredit ?? 0,
			'available_credit' => $availableCredit,
			'totalOutStanding' => $totalOutstanding ?? 0,
			'totalProfit' => $totalProfit ? $totalProfit : 0,
			'transactions' => $transactions,
			'currency' => $transactions->first()->currency ?? 'INR',
			'message' => 'Wallet history retrieved'
		];
	}
	public static function CreditAdjustment($user, $customer_id, $perPage)
	{
		$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
		$transactions = WalletModel::from('wallet as w')->where('w.AgencyID', $AgencyID)->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
			// ->whereNull('w.CreditSystemID')
			->where('w.IsCreditPayment', true)
			->where('w.is_profit', false)
			->orderBy('w.id', 'desc')
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('w.customer_id', $customer_id);
				}
			})->paginate($perPage, ['w.id', 'w.PType', 'w.amount', 'w.BalanceAmount', 'w.RefrenceNo', 'w.PaymentMode', 'w.PlanType', 'w.Remark', 'w.PaymentDate', 'w.created_at', 'u.name', 'u.email', 'u.mobile', 'u.countrycode']);
		$creditLimit = AgentCreditLimit::where('AgencyID', $AgencyID)
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('customer_id', $customer_id);
				}
			})->where('IsActive', true)->first();

		$totalOutstanding = AgentCreditLimit::where('AgencyID', $AgencyID)
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('customer_id', $customer_id);
				}
			})->sum('CurrentOutstanding');
		$availableCredit = $creditLimit ? ($creditLimit->CreditLimit) : 0;
		$totalProfit = WalletModel::where('AgencyID', $AgencyID)
			->where(function ($query) use ($customer_id, $user) {
				if ($customer_id > 0) {
					$query->where('customer_id', $customer_id);
				} else {
					$query->where('AgencyID', $user->id);
				}
			})->whereNull('CreditSystemID')->where('IsCreditPayment', false)->where('is_profit', true)->sum('amount');

		return [
			'status' => [
				'success' => true,
				'httpStatus' => 200,
			],
			'wallet_balance' => $transactions->first()->BalanceAmount ?? 0,
			'current_balance' => $transactions->first()->BalanceAmount ?? 0 + $availableCredit ?? 0,
			'available_credit' => $availableCredit,
			'totalOutStanding' => $totalOutstanding ?? 0,
			'totalProfit' => $totalProfit ? $totalProfit : 0,
			'transactions' => $transactions,
			'message' => 'Wallet history retrieved'
		];
	}

	public static function WalletHistoryAgent($user, $post, $perPage)
	{
		// $customer_id = $post->customer_id ?? 0;
		// $FromDate = $post->FromDate ?? null;
		// $ToDate = $post->ToDate ?? null;
		// $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
		// $fbSub = DB::table('flightbooking as fb')
		// 	->select('fb.customer_id', DB::raw('COALESCE(SUM(fb.PubFare), 0) + COALESCE(SUM(fb.SeatPrice), 0) + COALESCE(SUM(fb.BagPrice), 0) + COALESCE(SUM(fb.MealPrice), 0) as total_business'))
		// 	->where('fb.AgencyID', $AgencyID)->whereIn('fb.BookingStatus', [1, 29, 14])
		// 	->when($customer_id > 0, function ($q) use ($customer_id) {
		// 		$q->where('fb.customer_id', $customer_id);
		// 	})->where(
		// 		function ($query) use ($FromDate, $ToDate) {
		// 			if ($FromDate && $ToDate) {
		// 				return $query->whereBetween('fb.created_at', [$FromDate . " 00:00:00", $ToDate . " 23:59:59"]);
		// 			}
		// 		}
		// 	)
		// 	->groupBy('fb.customer_id');

		// $transactions = WalletModel::from('wallet as w')
		// 	->where('w.AgencyID', $AgencyID)
		// 	->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
		// 	->leftJoin('agentcreditlimit as cr', function ($join) {
		// 		$join->on('cr.customer_id', '=', 'w.customer_id')
		// 			->where('cr.IsActive', 1);
		// 	})
		// 	// Join the pre-aggregated bookings to avoid row multiplication
		// 	->leftJoinSub($fbSub, 'fbx', function ($join) {
		// 		$join->on('fbx.customer_id', '=', 'w.customer_id');
		// 	})
		// 	// ->whereNull('w.CreditSystemID')
		// 	// ->where('w.IsCreditPayment', false)
		// 	->where('w.is_profit', false)
		// 	->where(
		// 		function ($query) use ($FromDate, $ToDate) {
		// 			if ($FromDate && $ToDate) {
		// 				return $query->whereBetween('w.created_at', [$FromDate . " 00:00:00", $ToDate . " 23:59:59"]);
		// 			}
		// 		}
		// 	)
		// 	->when($customer_id > 0, function ($q) use ($customer_id) {
		// 		$q->where('w.customer_id', $customer_id);
		// 	})
		// 	->groupBy(
		// 		'w.customer_id',
		// 		'u.name',
		// 		'u.email',
		// 		'u.mobile',
		// 		'u.fname',
		// 		'u.lname',
		// 		'u.countrycode',
		// 		'cr.EffectiveFrom',
		// 		'cr.EffectiveTo',
		// 		'cr.CreditLimit',
		// 		'cr.CurrentOutstanding',
		// 		'fbx.total_business'
		// 	)
		// 	->orderBy('w.customer_id', 'desc')
		// 	->select([
		// 		'w.customer_id',
		// 		DB::raw('SUM(w.amount) as total_amount'),
		// 		// DB::raw('(w.BalanceAmount) as balance_amount'),
		// 		DB::raw('(SELECT w2.BalanceAmount FROM wallet AS w2 WHERE w2.customer_id = w.customer_id AND w2.is_profit = false AND ((w2.credit_refund = 1 AND w2.IsCreditPayment = true) OR (w2.credit_refund != 1 AND w2.IsCreditPayment = false)) ORDER BY w2.id DESC LIMIT 1) as balance_amount'),
		// 		DB::raw('SUM(CASE WHEN w.PType = "CR" THEN w.amount ELSE 0 END) as total_credit'),
		// 		DB::raw('SUM(CASE WHEN w.PType = "DR" THEN w.amount ELSE 0 END) as total_debit'),

		// 		DB::raw('((
		// 		(SELECT w2.BalanceAmount
		// 		FROM wallet AS w2
		// 		WHERE w2.customer_id = w.customer_id AND w2.is_profit = false AND ((w2.credit_refund = 1 AND w2.IsCreditPayment = true) OR (w2.credit_refund != 1 AND w2.IsCreditPayment = false))
		// 		ORDER BY w2.id DESC
		// 		LIMIT 1) + COALESCE(cr.CreditLimit, 0)) - COALESCE(cr.CurrentOutstanding, 0)) as net_balance'),

		// 		// Total business from pre-aggregated subquery (0 if null)
		// 		DB::raw('COALESCE(fbx.total_business, 0) as total_business'),

		// 		'u.name',
		// 		'u.email',
		// 		'u.mobile',
		// 		'u.fname',
		// 		'u.lname',
		// 		'u.countrycode',
		// 		'u.WalletStatus',
		// 		'cr.EffectiveFrom',
		// 		'cr.EffectiveTo',
		// 		DB::raw('COALESCE(cr.CreditLimit, 0) as CreditLimit'),
		// 		DB::raw('COALESCE(cr.CurrentOutstanding, 0) as CurrentOutstanding'),
		// 	])
		// 	->paginate($perPage);



		$customer_id = $post->customer_id ?? 0;
		$FromDate = $post->FromDate ?? null;
		$ToDate = $post->ToDate ?? null;
		$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;

		$fbSub = DB::table('flightbooking as fb')
			->select(
				'fb.customer_id',
				DB::raw('
            SUM(
                COALESCE(fb.PubFare,0)
                + COALESCE(fb.SeatPrice,0)
                + COALESCE(fb.BagPrice,0)
                + COALESCE(fb.MealPrice,0)
            ) as total_business
        ')
			)
			->where('fb.AgencyID', $AgencyID)
			->whereIn('fb.BookingStatus', [1, 29, 14])
			->when($customer_id > 0, function ($q) use ($customer_id) {
				$q->where('fb.customer_id', $customer_id);
			})->when($FromDate && $ToDate, function ($q) use ($FromDate, $ToDate) {
				$q->whereBetween(
					'fb.created_at',
					[
						$FromDate . ' 00:00:00',
						$ToDate . ' 23:59:59'
					]
				);
			})->groupBy('fb.customer_id');

		$latestBalance = DB::table('wallet as w2')
			->select(
				'w2.customer_id',
				DB::raw('MAX(w2.id) as latest_id')
			)
			->where('w2.is_profit', false)
			->where(function ($q) {
				$q->where(function ($q2) {
					$q2->where('w2.credit_refund', 1)
						->where('w2.IsCreditPayment', true);
				})
					->orWhere(function ($q2) {

						$q2->where('w2.credit_refund', '!=', 1)
							->where('w2.IsCreditPayment', false);
					});
			})->groupBy('w2.customer_id');

		$walletAgg = DB::table('wallet as w')->select(
			'w.customer_id',
			DB::raw('SUM(w.amount) as total_amount'),
			DB::raw('SUM(
            CASE
            WHEN w.PType="CR"
            THEN w.amount
            ELSE 0
            END
        ) as total_credit'),

			DB::raw('SUM(
            CASE
            WHEN w.PType="DR"
            THEN w.amount
            ELSE 0
            END
        ) as total_debit')
		)
			->where('w.AgencyID', $AgencyID)
			->where('w.is_profit', false)
			->when($customer_id > 0, function ($q) use ($customer_id) {
				$q->where('w.customer_id', $customer_id);
			})
			->when($FromDate && $ToDate, function ($q) use ($FromDate, $ToDate) {
				$q->whereBetween(
					'w.created_at',
					[
						$FromDate . ' 00:00:00',
						$ToDate . ' 23:59:59'
					]
				);
			})->groupBy('w.customer_id');

		$transactions = DB::query()
			->fromSub($walletAgg, 'wa')
			->leftJoin('users as u', 'u.id', '=', 'wa.customer_id')
			->leftJoin('agentcreditlimit as cr', function ($join) {
				$join->on(
					'cr.customer_id',
					'=',
					'wa.customer_id'
				)

					->where(
						'cr.IsActive',
						1
					);
			})
			->leftJoinSub(
				$fbSub,
				'fbx',
				function ($join) {

					$join->on(
						'fbx.customer_id',
						'=',
						'wa.customer_id'
					);
				}
			)
			->leftJoinSub(
				$latestBalance,
				'lb',
				function ($join) {

					$join->on(
						'lb.customer_id',
						'=',
						'wa.customer_id'
					);
				}
			)
			->leftJoin(
				'wallet as lw',
				'lw.id',
				'=',
				'lb.latest_id'
			)
			->select([
				'wa.customer_id',
				'wa.total_amount',
				'wa.total_credit',
				'wa.total_debit',
				DB::raw('COALESCE(lw.BalanceAmount,0) as balance_amount'),

				DB::raw('( COALESCE(lw.BalanceAmount,0) + COALESCE(cr.CreditLimit,0) - COALESCE(cr.CurrentOutstanding,0)) as net_balance
        '),

				DB::raw('COALESCE(fbx.total_business,0) as total_business'),
				'u.name',
				'u.email',
				'u.mobile',
				'u.fname',
				'u.lname',
				'u.countrycode',
				'u.WalletStatus',
				'cr.EffectiveFrom',
				'cr.EffectiveTo',
				DB::raw('COALESCE(cr.CreditLimit,0) as CreditLimit'),
				DB::raw('COALESCE(cr.CurrentOutstanding,0) as CurrentOutstanding')
			])
			->orderByDesc('fbx.total_business')
			->paginate($perPage);
		// pr($transactions);
		// die;
		$totalOutstanding = $transactions->sum('CurrentOutstanding') ?? 0;
		$availableCredit = $transactions->sum('CreditLimit') ?? 0;
		// $totalProfit = WalletModel::where('AgencyID', $AgencyID)
		// 	->where(function ($query) use ($customer_id, $user) {
		// 		if ($customer_id > 0) {
		// 			$query->where('customer_id', $customer_id);
		// 		} else {
		// 			$query->where('AgencyID', $user->id);
		// 		}
		// 	})->whereNull('CreditSystemID')->where('IsCreditPayment', false)->where('is_profit', true)->sum('amount');

		// pr($transactions->sum('balance_amount'));
		// die;
		return [
			'status' => [
				'success' => true,
				'httpStatus' => 200,
			],
			// 'wallet_balance' => (($transactions->sum('balance_amount') ?? 0) - $totalOutstanding),
			'wallet_balance' => (($transactions->sum('balance_amount') ?? 0)),
			'current_balance' => $transactions->sum('net_balance') ?? 0 + $availableCredit ?? 0,
			'available_credit' => $availableCredit,
			'totalOutStanding' => $totalOutstanding ?? 0,
			'total_business' => $transactions->sum('total_business') ?? 0,
			'transactions' => $transactions,
			'message' => 'Wallet history retrieved'
		];
	}
	public static function ProfitHistory($user, $customer_id, $perPage)
	{
		$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
		$transactions = WalletModel::from('wallet as w')->where('w.AgencyID', $AgencyID)->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
			->whereNull('w.CreditSystemID')
			->where('w.IsCreditPayment', false)
			->where('w.is_profit', true)
			->orderBy('w.created_at', 'desc')
			->where(function ($query) use ($customer_id, $user) {
				if ($customer_id > 0) {
					$query->where('w.customer_id', $customer_id);
				} else {
					$query->where('w.AgencyID', $user->id);
				}
			})
			->paginate($perPage, ['w.id', 'w.PType', 'w.amount', 'w.BalanceAmount', 'w.RefrenceNo', 'w.PaymentMode', 'w.PlanType', 'w.Remark', 'w.PaymentDate', 'w.created_at', 'u.name', 'u.email']);

		return [
			'status' => [
				'success' => true,
				'httpStatus' => 200,
			],
			'current_balance' => $transactions->sum('amount') ?? 0,
			'transactions' => $transactions,
			'message' => 'Wallet profit retrieved'
		];
	}
	public static function WalletBalance($user, $customer_id)
	{
		$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
		if ($user->UserType == 7) {
			$customer_id = $user->UserSysId;
		} else {
			$customer_id = $customer_id;
		}
		$walletTransactions = WalletModel::from('wallet as w')->where('w.AgencyID', $AgencyID)->where('w.customer_id', $customer_id)
			->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
			// ->whereNull('w.CreditSystemID')
			->where('w.IsCreditPayment', false)
			->where('w.is_profit', false)
			->orderBy('w.id', 'ASC')
			->get([
				'w.id',
				'w.PType',
				'w.amount',
				'w.BalanceAmount',
				'w.RefrenceNo',
				'w.Remark',
				'w.PaymentDate',
				'w.created_at',
				'u.name',
				'u.CreditLimitStatus',
				'u.BNPLCreditStatus',
				'u.UserType',
			]);
		// Get current wallet balance
		$currentWalletBalance = $walletTransactions->last()->BalanceAmount ?? 0;

		$UserType = $walletTransactions->last()->UserType ?? 0;
		$BNPLCreditStatus = $walletTransactions->last()->BNPLCreditStatus ?? 0;
		// Get available credit
		$creditLimit = AgentCreditLimit::where('agentcreditlimit.AgencyID', $AgencyID)->where('agentcreditlimit.customer_id', $customer_id)
			->join('users as u', 'u.id', '=', 'agentcreditlimit.customer_id')
			->where('u.CreditLimitStatus', true)
			->where('agentcreditlimit.IsActive', true)
			->first();

		$totalOutstanding = AgentCreditLimit::where('AgencyID', $AgencyID)
			->where(function ($query) use ($customer_id) {
				if ($customer_id > 0) {
					$query->where('customer_id', $customer_id);
				}
			})->sum('CurrentOutstanding');

		$availableCredit = $creditLimit ? ((float)$creditLimit->CreditLimit - (float)$totalOutstanding) : 0;
		$TermsDays = $creditLimit ? $creditLimit->TermsDays : 0;
		$EffectiveTo = $creditLimit ? $creditLimit->EffectiveTo : null;
		// pr($BNPLCreditStatus);
		// Calculate combined balance
		$combinedBalance = ($currentWalletBalance > 0) ? $currentWalletBalance + $availableCredit : $availableCredit;
		// $TotalRewardEarning = RewardEarn::TotalRewardEarning($user, ['user_id' => $customer_id]);
		$RewardEarningTemp = RewardEarnTemp::TotalRewardEarning($user, ['user_id' => $customer_id]);
		$reward = new RewardService();
		$result = $reward->getRewardSummary($customer_id, $AgencyID);
		$bnpl = new AgentBnplService();
		$BNPLsums = $bnpl->sumOfCreditLimit($user, $customer_id);
		return [
			'status' => [
				'success' => true,
				'httpStatus' => 200,
			],
			'user_id' => $customer_id,
			'UserType' => $UserType,
			'balances' => [
				'wallet_balance' => round($currentWalletBalance, 2),
				'available_credit' => round($availableCredit, 2),
				'bookable_balance' => round($combinedBalance, 2), //$currentWalletBalance,
				'totalOutStanding' => round($totalOutstanding, 2) ?? 0,
				'BNPL' => $BNPLsums ?? [],
				'reward_balance' => $result ?? [],
				'reward_balance_temp' => $RewardEarningTemp ?? [],
				'TermsDays' => $TermsDays ?? 0,
				'DueDate' => $EffectiveTo ?? null,
			],
			'message' => 'Wallet balances retrieved'
		];
	}


	public static function bookingUsingWalletBalance($user, $validated, $onCredit = 0)
	{
		$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
		$UserSysId = ($user) ? $user->id : 0;
		$validator = Validator::make($validated, [
			// 'customer_id' => 'required|integer|exists:users,id',
			'customer_id' => [
				'required',
				'integer',
				new BelongsToAgency($AgencyID),
			],
			'amount' => 'required|numeric|min:0.01',
			'RefrenceNo' => 'required|string|max:200',
			'PlanType' => 'required|integer',
			'Remark' => 'nullable|string|max:250',
		]);
		if ($validator->fails()) {
			$errors = json_encode($validator->messages());
			$errorArray = [];
			if (json_decode($errors, 1)) {
				foreach (json_decode($errors, 1) as $err) {
					foreach ($err as $errs) {
						$errorArray[] = ($errs);
					}
				}
			}
			return [
				'status' => [
					'success' => false,
					'httpStatus' => 422,
				],
				'message' => implode(',', $errorArray),
				'error' => $validator->messages(),
			];
		}

		$validated['AgencyID'] = $AgencyID;
		$validated['UserSysId'] = $UserSysId;

		return DB::transaction(function () use ($validated, $onCredit) {
			// Get current wallet balance
			$currentBalance = self::getCurrentBalance($validated['AgencyID'], $validated['customer_id']);
			if ($currentBalance < $validated['amount'] && $onCredit == 0) {
				return [
					'status' => [
						'success' => false,
						'httpStatus' => 400,
					],
					'message' => 'Insufficient wallet balance'
				];
			}

			// Create wallet entry (debit)
			$BalanceAmount = self::calculateNewBalance($validated['AgencyID'], $validated['customer_id'], $validated['amount'], 'DR');

			$wallet = WalletModel::create([
				'AgencyID' => $validated['AgencyID'],
				'UserSysId' => $validated['UserSysId'],
				'customer_id' => $validated['customer_id'],
				'PType' => 'DR',
				'amount' => $validated['amount'],
				'RefrenceNo' => $validated['RefrenceNo'],
				'PaymentMode' => isset($validated['PaymentMode']) ? $validated['PaymentMode'] : 'Paid For Order',
				'BalanceAmount' => $BalanceAmount,
				'Remark' => $validated['Remark'] ?? 'Booking for ' . $validated['RefrenceNo'],
				'PlanType' => $validated['PlanType'],
				'approved' => 1,
				'PaymentDate' => Carbon::now(),
			]);
			User::where('id', $validated['customer_id'])->update(['WalletBalance' => ($BalanceAmount)]);
			return [
				'status' => [
					'success' => true,
					'httpStatus' => 200,
				],
				'data' => $wallet,
				'message' => 'Wallet balance updated successful'
			];
		});
	}

	public static function getCurrentBalance($AgencyID, $customer_id)
	{
		$lastEntry = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
			->where(function ($query) {
				$query->where(function ($sub) {
					$sub->where('credit_refund', 1)
						->where('IsCreditPayment', true);
				})->orWhere(function ($sub) {
					$sub->where('credit_refund', '!=', 1)
						->where('IsCreditPayment', false);
				});
			})
			->where('is_profit', false)->orderBy('id', 'desc')
			->first();
		// $lastEntry = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
		// 	->whereNull('CreditSystemID')->where('IsCreditPayment', false)->where('is_profit', false)->orderBy('id', 'desc')
		// 	->first();

		return $lastEntry ? $lastEntry->BalanceAmount : 0;
	}
	public static function getAllDebitAmount($AgencyID, $customer_id, $BookingID = null)
	{
		$lastEntry = WalletModel::select([
			DB::raw('SUM(amount) as total_amount'),
		])->where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
			->where(function ($query) use ($BookingID) {
				if (!empty($BookingID)) {
					$query->where('RefrenceNo', $BookingID);
				}
			})
			->whereNull('CreditSystemID')->where('IsCreditPayment', false)->where('is_profit', false)->orderBy('id', 'desc')
			->first();

		return $lastEntry ? (float)$lastEntry->total_amount : 0;
	}
	public static function getLastDebitAmount($AgencyID, $customer_id, $BookingID = null, $credit = null)
	{
		// $lastEntry = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
		// 	->where(function ($query) use ($BookingID) {
		// 		if (!empty($BookingID)) {
		// 			$query->where('RefrenceNo', $BookingID);
		// 		}
		// 	})
		// 	->where('is_profit', false)->orderBy('id', 'desc')
		// 	->first();
		$lastEntry = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
			->where(function ($query) use ($BookingID) {
				if (!empty($BookingID)) {
					$query->where('RefrenceNo', $BookingID);
				}
			})->where(function ($query) use ($credit) {
				if ($credit == 'credit') {
					$query->whereNull('CreditSystemID');
				}
			})->where('IsCreditPayment', false)->where('is_profit', false)->orderBy('id', 'desc')
			->first();

		return $lastEntry ? (float)$lastEntry->amount : 0;
	}
	public static function getLastCreditAmount($AgencyID, $customer_id, $BookingID = null)
	{
		$walletTransactions = WalletModel::from('wallet as w')->where('w.AgencyID', $AgencyID)
			->where('w.customer_id', $customer_id)
			->where('w.RefrenceNo', $BookingID)
			->where('w.PType', 'CR')
			->whereNull('w.CreditSystemID')
			->where('w.IsCreditPayment', false)
			->where('w.is_profit', false)
			->first();
		return $walletTransactions;
	}
	public static function getTotalCreditAmount($AgencyID, $customer_id, $BookingID = null)
	{
		$total = WalletModel::from('wallet as w')
			->where('w.AgencyID', $AgencyID)
			->where('w.customer_id', $customer_id)
			->where('w.RefrenceNo', $BookingID)
			->where('w.PType', 'CR')
			->whereNull('w.CreditSystemID')
			->where('w.IsCreditPayment', false)
			->where('w.is_profit', false)
			->sum('w.amount');

		return $total;
	}

	public static function calculateNewBalance($AgencyID, $customer_id, $amount, $PType, $isCreditPayment = false, $hasCreditSystem = false)
	{
		$lastEntry = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
			->where(function ($query) {
				$query->where('is_profit', false);
			})->where(function ($query) {
				$query->where(function ($sub) {
					$sub->where('credit_refund', 1)
						->where('IsCreditPayment', true);
				})->orWhere(function ($sub) {
					$sub->where('credit_refund', '!=', 1)
						->where('IsCreditPayment', false);
				});
			})
			->orderBy('id', 'desc')
			->first();
		// $lastEntry = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
		// 	->where(function ($query) {
		// 		$query->whereNull('CreditSystemID')
		// 			->where('IsCreditPayment', false)->where('is_profit', false);
		// 	})
		// 	->orderBy('id', 'desc')
		// 	->first();

		$currentBalance = $lastEntry ? $lastEntry->BalanceAmount : 0;
		// Only adjust balance if this is NOT a credit system transaction
		if (!$isCreditPayment && !$hasCreditSystem) {
			return $PType === 'CR'
				? $currentBalance + $amount
				: $currentBalance - $amount;
		}
		// For credit system transactions, return the last actual balance
		return $currentBalance;
	}

	public static function salesReport($user, $filterData = [])
	{
		$from = isset($filterData['from']) ? $filterData['from'] : null;
		$to   = isset($filterData['to']) ? $filterData['to'] : null;
		// pr($filterData);
		// die;
		$data = WalletModel::select(
			'AgencyID',
			DB::raw("SUM(CASE WHEN PType = 'DR' AND PlanType = 1 AND PaymentMode = 'Paid For Order' THEN 1 ELSE 0 END) as flight_trans_count"),
			DB::raw("SUM(CASE WHEN PType='DR' AND PaymentMode = 'Paid For Order' THEN amount ELSE 0 END) as total_sales"),
			DB::raw("SUM(CASE WHEN PType='DR' AND PlanType=1 AND PaymentMode = 'Paid For Order' THEN amount ELSE 0 END) as flight_sales"),
			// DB::raw("SUM(CASE WHEN PType='DR' AND PlanType=2 AND PaymentMode = 'Paid For Order' THEN amount ELSE 0 END) as hotel_sales"),
			DB::raw("
				SUM(
					CASE
						WHEN (
							PType = 'DR'
							AND PlanType = 2
							AND PaymentMode = 'Paid For Order'
						)
						OR (
							CreditSystemID IS NOT NULL
							AND PType = 'CR'
							AND PlanType = 2
						)
						THEN amount
						ELSE 0
					END
				) as hotel_sales
			"),
			DB::raw("
				SUM(
					CASE
						WHEN (
							PType = 'DR'
							AND PlanType = 3
							AND PaymentMode = 'Paid For Order'
						)
						OR (
							CreditSystemID IS NOT NULL
							AND PType = 'CR'
							AND PlanType = 3
						)
						THEN amount
						ELSE 0
					END
				) as package_sales
			"),
			// DB::raw("SUM(CASE WHEN PType='DR' AND PlanType=3 AND PaymentMode = 'Paid For Order' THEN amount ELSE 0 END) as package_sales"),
			DB::raw("SUM(CASE WHEN PType='CR' AND Remark LIKE '%Wallet Recharge%' THEN amount ELSE 0 END) as pg_credit"),
			DB::raw("SUM(CASE WHEN PType='DR' AND (PaymentMode='Paid For Order' OR PaymentMode='Paid For BNPL Pending') THEN amount ELSE 0 END) as total_wallet_used"),
			DB::raw("SUM(CASE WHEN PType='CR' AND PaymentMode='Refund' THEN amount ELSE 0 END) as total_refund"),
		)->where(function ($query) use ($user) {
			$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
			$query->where('AgencyID', $AgencyID);
		})->where(function ($query) use ($filterData) {
			if (!empty($filterData)) {
				if (isset($filterData['customer_id']) && $filterData['customer_id'] > 0) {
					$query->where('customer_id', $filterData['customer_id']);
				}
			}
		})->where(function ($query) use ($from, $to) {
			if (!empty($from) && !empty($to)) {
				$query->whereBetween('created_at', [$from . " 00:00:00", $to . " 23:59:59"]);
			}
		})->where('approved', 1)->first();

		$Trandata = PaymentTransaction::select(
			'agency_id',
			DB::raw("SUM( IFNULL(service_charge,0) + IFNULL(service_tax,0)) as pg_charges"),
			DB::raw("SUM(amount) as pg_credit"),
			DB::raw("SUM(settlement_amount) as pg_settlement_amount"),
			DB::raw(" COUNT(id) as pg_trans_count")
		)->where(function ($query) use ($user) {
			$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
			$query->where('agency_id', $AgencyID);
		})->where(function ($query) use ($filterData) {
			if (!empty($filterData)) {
				if (isset($filterData['customer_id']) && $filterData['customer_id'] > 0) {
					$query->where('customer_id', $filterData['customer_id']);
				}
			}
		})->where(function ($query) use ($from, $to) {
			if (!empty($from) && !empty($to)) {
				$query->where('created_at', '>=', $from . " 00:00:00")->where('created_at', '<=', $to . " 23:59:59");
			}
		})->where(function ($query) {
			$query->where('status', 'success')->orWhere('status', 'autorefunded');
		})->first();

		$BnplUseddata = AgentBnplTransaction::select(
			'AgencyID',
			DB::raw(" SUM(amount) as bnpl_used"),
		)->where(function ($query) use ($user) {
			$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
			$query->where('AgencyID', $AgencyID);
		})->where(function ($query) use ($filterData) {
			if (!empty($filterData)) {
				if (isset($filterData['customer_id']) && $filterData['customer_id'] > 0) {
					$query->where('agent_id', $filterData['customer_id']);
				}
			}
		})->where(function ($query) use ($from, $to) {
			if (!empty($from) && !empty($to)) {
				$query->where('created_at', '>=', $from . " 00:00:00")->where('created_at', '<=', $to . " 23:59:59");
			}
		})->where(function ($query) {
			$query->where('transaction_type', 'debit');
		})->first();

		$VIPAgency = 1;
		$VipUpgrade = User::select(
			DB::raw("SUM(CASE WHEN invoices.TPSystemID=0 THEN invoices.SubTotal ELSE 0 END) as totalAmount"),
			DB::raw("SUM(CASE WHEN invoices.TPSystemID=0 THEN invoices.TotalTaxAmount ELSE 0 END) as TotalTaxAmount"),
		)->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
			->join('invoices', 'invoices.customer_id', '=', 'users.id')
			->when($VIPAgency == 1, function ($query) {
				return $query->join('user_card', 'user_card.user_id', '=', 'users.id');
			})
			->where(function ($query) use ($user) {
				$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
				$query->where('users.AgencyID', $AgencyID)->where('invoices.AgencyID', $AgencyID);
			})->where('users.UserType', 2)->when($VIPAgency == 1, function ($query) {
				$query->where('user_card.status', 'active')->orderBy('user_card.created_at', 'DESC');
			}, function ($query) {
				$query->orderBy('users.id', 'DESC');
			})->where(function ($query) use ($filterData) {
				if (!empty($filterData)) {
					if (isset($filterData['customer_id']) && $filterData['customer_id'] > 0) {
						$query->where('invoices.customer_id', $filterData['customer_id']);
					}
				}
			})->where(function ($query) use ($from, $to) {
				if (!empty($from) && !empty($to)) {
					$query->where('invoices.created_at', '>=', $from . " 00:00:00")->where('invoices.created_at', '<=', $to . " 23:59:59");
				}
			})->first();

		$dataCredit = CreditTransaction::select(
			'AgencyID',
			DB::raw("SUM(CASE WHEN (Status = 'PAID' || Status = 'PARTIAL') THEN Amount ELSE 0 END) as credit_paid"),
			DB::raw("SUM(CASE WHEN (Status = 'PENDING' || Status = 'OVERDUE') THEN Amount ELSE 0 END) as credit_pending"),
		)->where(function ($query) use ($user) {
			$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
			$query->where('AgencyID', $AgencyID);
		})->where(function ($query) use ($filterData) {
			if (!empty($filterData)) {
				if (isset($filterData['customer_id']) && $filterData['customer_id'] > 0) {
					$query->where('customer_id', $filterData['customer_id']);
				}
			}
		})->where(function ($query) use ($from, $to) {
			if (!empty($from) && !empty($to)) {
				$query->whereBetween('created_at', [$from . " 00:00:00", $to . " 23:59:59"]);
			}
		})->first();

		$flight = FlightBookingModel::SalesReport($filterData, $user);
		// pr($flight);
		// die;

		return [
			'AgencyID' => $data->AgencyID ?? 0,
			'flight_count' => $flight->count ?? 0,
			'total_sales' => $flight->total_sales ?? 0, //$data->total_sales ?? 0,
			'total_wallet_used' => ($data->total_wallet_used) ?? 0,
			'flight_sales' => $flight->total_sales ?? 0,
			'hotel_sales' => $data->hotel_sales ?? 0,
			'package_sales' => $data->package_sales ?? 0,
			'total_refund' => $data->total_refund ?? 0,
			'pg_charges' => $Trandata->pg_charges ?? 0,
			'pg_trans_count' => $Trandata->pg_trans_count ?? 0,
			'net_sales' => ($flight->total_sales - $data->total_refund) ?? 0,
			'pg_credit' => $Trandata->pg_credit ?? 0,
			// 'pg_credit' => ($Trandata->pg_credit + $Trandata->pg_charges) ?? 0,
			'pg_settlement' => $Trandata->pg_settlement_amount ?? 0,
			'total_bnpl_used' => $BnplUseddata->bnpl_used ?? 0,
			'VIPtotalAmount' => $VipUpgrade->totalAmount ?? 0,
			'VIPTotalTaxAmount' => $VipUpgrade->TotalTaxAmount ?? 0,
			'credit_paid' => $dataCredit->credit_paid ?? 0,
			'credit_pending' => $dataCredit->credit_pending ?? 0,
			'TotalOfflineSales' => $flight->TotalOfflineSales ?? 0,
		];
	}
	public static function TaxReport($user, $filterData = [])
	{
		$from = isset($filterData['from']) ? $filterData['from'] : null;
		$to   = isset($filterData['to']) ? $filterData['to'] : null;

		$FlightAmendment = FlightAmendment::select(
			'AgencyID',
			DB::raw(" SUM(service_fee) as TotalAmendServiceFee"),
			DB::raw(" SUM(service_gst) as TotalAmendServiceGst"),
		)->where(function ($query) use ($user) {
			$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
			$query->where('AgencyID', $AgencyID);
		})->where(function ($query) use ($from, $to) {
			if (!empty($from) && !empty($to)) {
				$query->where('created_at', '>=', $from . " 00:00:00")->where('created_at', '<=', $to . " 23:59:59");
			}
		})->first();

		$VIPAgency = 1;
		$VipUpgrade = User::select(
			DB::raw("SUM(CASE WHEN invoices.TPSystemID=0 THEN invoices.SubTotal ELSE 0 END) as totalAmount"),
			DB::raw("SUM(CASE WHEN invoices.TPSystemID=0 THEN invoices.TotalTaxAmount ELSE 0 END) as TotalTaxAmount"),
		)->join('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
			->join('invoices', 'invoices.customer_id', '=', 'users.id')
			->when($VIPAgency == 1, function ($query) {
				return $query->join('user_card', 'user_card.user_id', '=', 'users.id');
			})
			->where(function ($query) use ($user) {
				$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
				$query->where('users.AgencyID', $AgencyID)->where('invoices.AgencyID', $AgencyID);
			})->where('users.UserType', 2)->when($VIPAgency == 1, function ($query) {
				$query->where('user_card.status', 'active')->orderBy('user_card.created_at', 'DESC');
			}, function ($query) {
				$query->orderBy('users.id', 'DESC');
			})->where(function ($query) use ($filterData) {
				if (!empty($filterData)) {
					if (isset($filterData['customer_id']) && $filterData['customer_id'] > 0) {
						$query->where('invoices.customer_id', $filterData['customer_id']);
					}
				}
			})->where(function ($query) use ($from, $to) {
				if (!empty($from) && !empty($to)) {
					$query->where('invoices.created_at', '>=', $from . " 00:00:00")->where('invoices.created_at', '<=', $to . " 23:59:59");
				}
			})->first();


		$flight = FlightBookingModel::SalesReport($filterData, $user);

		// pr($flight);
		// die;
		return [
			'flight_count' => $flight->count ?? 0,
			'flight_sales' => $flight->total_sales ?? 0,
			'TotalFixedMarkUp' => $flight->TotalFixedMarkUp ?? 0,
			'TotalGSTOnMarkUp' => $flight->TotalGSTOnMarkUp ?? 0,
			'TotalProAmt' => $flight->TotalProAmt ?? 0,
			'TotalGSTOnPro' => $flight->TotalGSTOnPro ?? 0,
			'TotalPenaltyAmount' => $flight->TotalPenaltyAmount ?? 0,
			'TotalPenaltyGst' => $flight->TotalPenaltyGst ?? 0,
			'TotalDisruptionfee' => $flight->TotalDisruptionfee ?? 0,
			'TotalDisruptionGst' => $flight->TotalDisruptionGst ?? 0,
			'VIPtotalAmount' => $VipUpgrade->totalAmount ?? 0,
			'VIPTotalTaxAmount' => $VipUpgrade->TotalTaxAmount ?? 0,
			'TotalAmendServiceFee' => $FlightAmendment->TotalAmendServiceFee ?? 0,
			'TotalAmendServiceGst' => $FlightAmendment->TotalAmendServiceGst ?? 0,
		];
	}
	public static function vipLedgerExport($user, $filterData = [])
	{
		$from = isset($filterData['from']) ? $filterData['from'] : null;
		$to   = isset($filterData['to']) ? $filterData['to'] : null;
		$VIPAgency = 1;
		$VipUpgrade = User::select(
			'users.name',
			'users.email',
			'users.mobile',
			'users.countrycode',
			'users.UserType',
			'invoices.created_at',
			'invoices.invoiceNo',
			'invoices.SubTotal',
			'invoices.TotalTaxAmount',
		)->join('invoices', 'invoices.customer_id', '=', 'users.id')
			->when($VIPAgency == 1, function ($query) {
				return $query->join('user_card', 'user_card.user_id', '=', 'users.id');
			})
			->where(function ($query) use ($user) {
				$AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
				$query->where('users.AgencyID', $AgencyID)->where('invoices.AgencyID', $AgencyID);
			})->where('users.UserType', 2)->when($VIPAgency == 1, function ($query) {
				$query->where('user_card.status', 'active')->orderBy('user_card.created_at', 'DESC');
			}, function ($query) {
				$query->orderBy('users.id', 'DESC');
			})->where(function ($query) use ($filterData) {
				if (!empty($filterData)) {
					if (isset($filterData['customer_id']) && $filterData['customer_id'] > 0) {
						$query->where('invoices.customer_id', $filterData['customer_id']);
					}
				}
			})->where(function ($query) use ($from, $to) {
				if (!empty($from) && !empty($to)) {
					$query->where('invoices.created_at', '>=', $from . " 00:00:00")->where('invoices.created_at', '<=', $to . " 23:59:59");
				}
			})->where('invoices.TPSystemID', 0)->groupBy(
				'users.name',
				'users.email',
				'users.mobile',
				'users.countrycode',
				'users.UserType',
				'invoices.created_at',
				'invoices.invoiceNo',
				'invoices.SubTotal',
				'invoices.TotalTaxAmount',
			)->get();

		return $VipUpgrade;
	}
}
