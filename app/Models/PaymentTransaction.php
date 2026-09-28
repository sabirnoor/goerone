<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'agency_id',
        'customer_id',
        'txnid',
        'easepayid',
        'amount',
        'service_tax',
        'service_charge',
        'discount_amount',
        'settlement_amount',
        'cash_back_percentage',
        'deduction_percentage',
        'status',
        'unmappedstatus',
        'error',
        'error_message',
        'cancellation_reason',
        'firstname',
        'email',
        'phone',
        'productinfo',
        'payment_source',
        'auth_ref_num',
        'bank_ref_num',
        'udf1',
        'udf2',
        'udf3',
        'udf6',
        'udf7',
        'udf8',
        'udf9',
        'udf10',
        'addedon'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'service_tax' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'settlement_amount' => 'decimal:2',
        'cash_back_percentage' => 'decimal:2',
        'deduction_percentage' => 'decimal:2',
        'addedon' => 'datetime',
    ];

    /**
     * Get the agency user
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    /**
     * Get the customer user
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public static function Failedhistory($user, $customer_id, $perPage)
    {
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;

        $transactions = PaymentTransaction::from('payment_transactions as w')->where('w.agency_id', $AgencyID)
            ->leftJoin('users as u', 'u.id', '=', 'w.customer_id')
            ->orderBy('w.id', 'desc')
            ->where(function ($query) use ($customer_id) {
                if ($customer_id > 0) {
                    $query->where('w.customer_id', $customer_id);
                }
            })->where(
                function ($query) use ($user) {
                    $query->when($user->UserType == 1, function ($q) use ($user) {
                        $q->where('w.agency_id', $user->id);
                    })
                        ->when($user->UserType == 4, function ($q) use ($user) {
                            $q->where('w.agency_id', $user->AgencyID);
                        })
                        ->when(!in_array($user->UserType, [1, 4]), function ($q) use ($user) {
                            $q->where('w.customer_id', $user->id);
                        });
                }
            )->where('w.status', '!=', 'success')->paginate($perPage, [
                'w.id',
                'w.txnid',
                'w.easepayid',
                'w.amount',
                'w.service_tax',
                'w.service_charge',
                'w.settlement_amount',
                'w.status',
                'w.error',
                'w.error_message',
                'w.cancellation_reason',
                'w.productinfo',
                'w.payment_source',
                'w.bank_ref_num',
                'w.udf1',
                'w.udf3',
                'w.created_at',
                'u.name',
                'u.email',
                'u.mobile',
                'u.countrycode'
            ]);

        // $creditLimit = AgentCreditLimit::where('AgencyID', $AgencyID)
        //     ->where(function ($query) use ($customer_id) {
        //         if ($customer_id > 0) {
        //             $query->where('customer_id', $customer_id);
        //         }
        //     })->where('IsActive', true)->first();

        // $totalOutstanding = AgentCreditLimit::where('AgencyID', $AgencyID)
        //     ->where(function ($query) use ($customer_id) {
        //         if ($customer_id > 0) {
        //             $query->where('customer_id', $customer_id);
        //         }
        //     })->sum('CurrentOutstanding');
        // $availableCredit = $creditLimit ? ($creditLimit->CreditLimit) : 0;
        // $totalProfit = WalletModel::where('AgencyID', $AgencyID)
        //     ->where(function ($query) use ($customer_id, $user) {
        //         if ($customer_id > 0) {
        //             $query->where('customer_id', $customer_id);
        //         } else {
        //             $query->where('AgencyID', $user->id);
        //         }
        //     })->whereNull('CreditSystemID')->where('IsCreditPayment', false)->where('is_profit', true)->sum('amount');

        return [
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'wallet_balance' => 0,
            'current_balance' => 0,
            'available_credit' => 0,
            'totalOutStanding' => $totalOutstanding ?? 0,
            'totalProfit' => 0,
            'transactions' => $transactions,
            'message' => 'Payment failed history retrieved'
        ];
    }
}
