<?php

namespace App\Services;

use App\Models\RewardWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RewardService
{
    /**
     * Transfer points from payer to payee
     */
    public function transferPoints(array $data): array
    {
        return DB::transaction(function () use ($data) {

            $txnRef = $data['ReferenceNo'] ?? RewardWallet::generateTxnRef('PAY');
            // $txnRef = $data['ReferenceNo'] ?? Str::uuid()->toString();
            $points = $data['points'];
            $agencyId = $data['AgencyID'];
            $UserSysId = $data['UserSysId'] ?? 0;
            $payerId = $data['payer_id'];
            $payeeId = $data['payee_id'];
            $description = $data['description'] ?? 'Points transfer';
            if ($points > 0) {
                // Check if payer has sufficient balance
                $payerBalance = $this->getCustomerNetBalance($payerId, $agencyId);
                $payerBalance = number_format(($payerBalance), 2, '.', '');

                if ($payerBalance < $points) {
                    throw new \Exception('Insufficient reward balance');
                }

                // Get current balance for both parties
                $payerCurrentBalance = $this->getCustomerNetBalance($payerId, $agencyId);
                $payeeCurrentBalance = $this->getCustomerNetBalance($payeeId, $agencyId);
                // pr($payerCurrentBalance);
                // pr($payeeCurrentBalance);
                // die;
                // Create DR entry for payer
                $debitEntry = RewardWallet::create([
                    'ReferenceNo' => $txnRef,
                    'AgencyID' => $agencyId,
                    'UserSysId' => $UserSysId,
                    'payer_id' => $payerId,
                    'customer_id' => $payerId,
                    'payee_id' => $payeeId,
                    'type' => RewardWallet::TYPE_DEBIT,
                    'points' => $points,
                    'balance_points' => $payerCurrentBalance - $points,
                    'description' => $description,
                    'status' => RewardWallet::STATUS_SUCCESS,
                    'RewardMode' => $data['RewardMode'] ?? null,
                    'PlanType' => $data['PlanType'] ?? 0,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    'ip_address' => $data['ip_address'] ?? request()->ip(),
                ]);

                // Create CR entry for payee
                $creditEntry = RewardWallet::create([
                    'ReferenceNo' => $txnRef,
                    'AgencyID' => $agencyId,
                    'UserSysId' => $UserSysId,
                    'payer_id' => $payerId,
                    'payee_id' => $payeeId,
                    'customer_id' => $payeeId,
                    'type' => RewardWallet::TYPE_CREDIT,
                    'points' => $points,
                    'balance_points' => $payeeCurrentBalance + $points,
                    'description' => $description,
                    'status' => RewardWallet::STATUS_SUCCESS,
                    'RewardMode' => $data['RewardMode'] ?? null,
                    'PlanType' => $data['PlanType'] ?? 0,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    'ip_address' => $data['ip_address'] ?? request()->ip(),
                ]);

                return [
                    'success' => true,
                    'ReferenceNo' => $txnRef,
                    'debit_entry' => $debitEntry,
                    'credit_entry' => $creditEntry,
                    'message' => 'SUCCESS',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Zero transaction not allowed',
                ];
            }
        });
    }

    /**
     * Add points to customer (Credit)
     */
    public function addPoints(array $data): RewardWallet
    {

        $currentBalance = $this->getCustomerNetBalance($data['payee_id'], $data['AgencyID']);
        // pr($currentBalance);
        // die;
        return RewardWallet::create([
            'ReferenceNo' => $data['ReferenceNo'] ?? RewardWallet::generateTxnRef('EAR'),
            'AgencyID' => $data['AgencyID'],
            'UserSysId' => $data['UserSysId'],
            'payer_id' => $data['payer_id'] ?? null,
            'payee_id' => $data['payee_id'],
            'customer_id' => $data['payee_id'],
            'type' => RewardWallet::TYPE_CREDIT,
            'points' => $data['points'],
            'balance_points' => $currentBalance + $data['points'],
            'description' => $data['description'] ?? 'Points credited',
            'status' => RewardWallet::STATUS_SUCCESS,
            'RewardMode' => $data['RewardMode'] ?? null,
            'PlanType' => $data['PlanType'] ?? 0,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'ip_address' => $data['ip_address'] ?? request()->ip(),
        ]);
    }

    /**
     * Deduct points from customer (Debit)
     */
    public function deductPoints(array $data): RewardWallet
    {
        $currentBalance = $this->getCustomerBalance($data['payer_id'], $data['agency_id']);

        if ($currentBalance < $data['points']) {
            throw new \Exception('Insufficient balance');
        }

        return RewardWallet::create([
            'ReferenceNo' => $data['ReferenceNo'] ?? Str::uuid()->toString(),
            'AgencyID' => $data['agency_id'],
            'payer_id' => $data['payer_id'],
            'payee_id' => $data['payee_id'] ?? null,
            'type' => RewardWallet::TYPE_DEBIT,
            'points' => $data['points'],
            'balance_points' => $currentBalance - $data['points'],
            'description' => $data['description'] ?? 'Points debited',
            'status' => RewardWallet::STATUS_SUCCESS,
            'RewardMode' => $data['RewardMode'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'ip_address' => $data['ip_address'] ?? request()->ip(),
        ]);
    }

    /**
     * Get customer reward balance
     */
    public function getCustomerBalance(int $customerId, ?int $agencyId = null): float
    {
        $query = RewardWallet::where('payee_id', $customerId)
            ->where('status', RewardWallet::STATUS_SUCCESS);

        if ($agencyId) {
            $query->where('AgencyID', $agencyId);
        }

        $latestEntry = $query->latest('id')->first();
        return $latestEntry ? $latestEntry->balance_points : 0;
    }

    public function getCustomerNetBalance(int $customerId, ?int $agencyId = null): float
    {
        $query = RewardWallet::where('status', RewardWallet::STATUS_SUCCESS);

        if ($agencyId) {
            $query->where('AgencyID', $agencyId);
        }

        // Total credits received as payee
        $totalCredits = (clone $query)->where('payee_id', $customerId)
            ->where('type', RewardWallet::TYPE_CREDIT)
            ->sum('points');

        // Total debits made as payer
        $totalDebits = (clone $query)->where('payer_id', $customerId)
            ->where('type', RewardWallet::TYPE_DEBIT)
            ->sum('points');

        return $totalCredits - $totalDebits;
    }


    public function getCustomerLedger(?int $customerId = null, ?int $agencyId = null, ?int $perPage = 15, $validated = null)
    {
        $query = RewardWallet::select(
            'reward_wallet.id',
            'reward_wallet.ReferenceNo',
            'reward_wallet.type',
            'reward_wallet.points',
            'reward_wallet.balance_points',
            'reward_wallet.description',
            'reward_wallet.status',
            'reward_wallet.RewardMode',
            'reward_wallet.PlanType',
            'reward_wallet.created_at',

            'payer.name as payer_name',
            'payer.email as payer_email',
            'payer.mobile as payer_mobile',

            'payee.name as payee_name',
            'payee.email as payee_email',
            'payee.mobile as payee_mobile',

            'cus.name',
            'cus.mobile',
            'cus.email',
            'cus.UserType',
            'cus.countrycode',
        )
            ->leftJoin('users as cus', 'cus.id', '=', 'reward_wallet.customer_id')
            ->leftJoin('users as payer', 'payer.id', '=', 'reward_wallet.payer_id')
            ->leftJoin('users as payee', 'payee.id', '=', 'reward_wallet.payee_id')
            ->where('reward_wallet.status', RewardWallet::STATUS_SUCCESS)
            ->orderBy('reward_wallet.id', 'desc')
            ->where(
                function ($query) use ($validated) {
                    if (!empty($validated['bookingID'])) {
                        return $query->where('reward_wallet.ReferenceNo', $validated['bookingID']);
                    }
                    if (!empty($validated['FromDate']) && !empty($validated['ToDate'])) {
                        return $query->whereBetween('reward_wallet.created_at', [date('Y-m-d', strtotime($validated['FromDate'])) . " 00:00:00", date('Y-m-d', strtotime($validated['ToDate'])) . " 23:59:59"]);
                    }
                }
            );

        // Filter by customer
        if ($customerId) {
            $query->where(function ($q) use ($customerId) {
                $q->where('reward_wallet.customer_id', $customerId);
            });
        }
        // Filter by AgencyID
        if ($agencyId) {
            $query->where('reward_wallet.AgencyID', $agencyId);
        }

        $query->orderBy('reward_wallet.created_at', 'desc')
            ->orderBy('reward_wallet.id', 'desc');

        // Pagination
        $paginator = $query->paginate($perPage);

        // Calculate running balance if customerId is provided
        $runningBalance = 0;
        if ($customerId) {
            $currentBalance = $this->getCustomerNetBalance($customerId, $agencyId);
            $runningBalance = $currentBalance;
        }

        // Transform items
        $transformedItems = $paginator->getCollection()->map(function ($transaction) use ($customerId, &$runningBalance) {

            // User details already available
            $transaction->payer_name  = $transaction->payer_name;
            $transaction->payer_email = $transaction->payer_email;
            $transaction->payer_mobile = $transaction->payer_mobile;

            $transaction->payee_name = $transaction->payee_name;
            $transaction->payee_email = $transaction->payee_email;
            $transaction->payee_mobile = $transaction->payee_mobile;

            // Perspective based transaction type and balance calculation
            if ($customerId) {
                // Determine if customer is sender or receiver
                $isCustomerPayer = ($transaction->payer_id == $customerId);
                $isCustomerPayee = ($transaction->payee_id == $customerId);

                if ($isCustomerPayee && $transaction->type == RewardWallet::TYPE_CREDIT) {
                    $transaction->customer_transaction_type = 'CREDIT_RECEIVED';
                    $transaction->amount = $transaction->points;
                    $transaction->is_credit = true;
                    $transaction->mode = 'Earn';

                    // Calculate running balance (working backwards from current balance)
                    $transaction->running_balance = $runningBalance;
                    $runningBalance -= $transaction->points; // Subtract credit when going backwards

                } elseif ($isCustomerPayer && $transaction->type == RewardWallet::TYPE_DEBIT) {
                    $transaction->customer_transaction_type = 'DEBIT_SENT';
                    $transaction->amount = -$transaction->points;
                    $transaction->is_credit = false;
                    $transaction->mode = 'Pay';

                    // Calculate running balance (working backwards from current balance)
                    $transaction->running_balance = $runningBalance;
                    $runningBalance += $transaction->points; // Add debit when going backwards

                } else {
                    $transaction->customer_transaction_type = 'OTHER';
                    $transaction->amount = 0;
                    $transaction->is_credit = null;
                    $transaction->mode = 'Other';
                    $transaction->running_balance = $runningBalance;
                }

                // Counterparty information - Show the other party's name
                if ($isCustomerPayer) {
                    $transaction->counterparty_id = $transaction->payee_id;
                    $transaction->counterparty_name = $transaction->payee_name;
                    $transaction->counterparty_email = $transaction->payee_email;
                    $transaction->counterparty_mobile = $transaction->payee_mobile;
                    $transaction->counterparty_type = 'RECEIVER';

                    // For display purposes
                    $transaction->from_name = $transaction->payer_name; // Customer is sender
                    $transaction->to_name = $transaction->payee_name;   // Receiver is counterparty
                } else {
                    $transaction->counterparty_id = $transaction->payer_id;
                    $transaction->counterparty_name = $transaction->payer_name;
                    $transaction->counterparty_email = $transaction->payer_email;
                    $transaction->counterparty_mobile = $transaction->payer_mobile;
                    $transaction->counterparty_type = 'SENDER';

                    // For display purposes  
                    $transaction->from_name = $transaction->payer_name; // Sender is counterparty
                    $transaction->to_name = $transaction->payee_name;   // Customer is receiver
                }
            } else {
                // General ledger view
                $transaction->customer_transaction_type = $transaction->type;
                $transaction->amount = $transaction->type == RewardWallet::TYPE_CREDIT
                    ? $transaction->points
                    : -$transaction->points;
                $transaction->is_credit = $transaction->type == RewardWallet::TYPE_CREDIT;
                $transaction->mode = $transaction->type == RewardWallet::TYPE_CREDIT ? 'Earn' : 'Pay';
                $transaction->counterparty_id = null;
                $transaction->counterparty_name = null;
                $transaction->counterparty_email = null;
                $transaction->counterparty_mobile = null;
                $transaction->counterparty_type = 'TRANSACTION';
                $transaction->from_name = $transaction->payer_name;
                $transaction->to_name = $transaction->payee_name;

                // For general ledger, use the original balance_points
                $transaction->running_balance = $transaction->balance_points;
            }

            return $transaction;
        });

        $paginator->setCollection($transformedItems);

        // Add balance summary to paginator if customer specific
        if ($customerId) {
            $paginator->balance_summary = [
                'current_balance' => $this->getCustomerNetBalance($customerId, $agencyId),
                'total_transactions' => $query->count(),
                'customer_id' => $customerId,
                'agency_id' => $agencyId
            ];
        }

        return $paginator;
    }

    // public function getCustomerLedger(?int $customerId = null, ?int $agencyId = null, ?int $perPage = 15)
    // {
    //     $query = RewardWallet::select(
    //         'reward_wallet.*',
    //         'payer.name as payer_name',
    //         'payer.email as payer_email',
    //         'payer.mobile as payer_mobile',
    //         'payee.name as payee_name',
    //         'payee.email as payee_email',
    //         'payee.mobile as payee_mobile'
    //     )
    //         ->leftJoin('users as payer', 'payer.id', '=', 'reward_wallet.payer_id')
    //         ->leftJoin('users as payee', 'payee.id', '=', 'reward_wallet.payee_id')
    //         ->where('reward_wallet.status', RewardWallet::STATUS_SUCCESS)
    //         ->orderBy('reward_wallet.created_at', 'desc')
    //         ->orderBy('reward_wallet.id', 'desc');

    //     // Filter by customer
    //     if ($customerId) {
    //         $query->where(function ($q) use ($customerId) {
    //             $q->where('reward_wallet.payer_id', $customerId)
    //                 ->orWhere('reward_wallet.payee_id', $customerId);
    //         });
    //     }

    //     // Filter by AgencyID
    //     if ($agencyId) {
    //         $query->where('reward_wallet.AgencyID', $agencyId);
    //     }
    //     $query->orderBy('reward_wallet.created_at', 'desc')
    //         ->orderBy('reward_wallet.id', 'desc');
    //     // Pagination
    //     $paginator = $query->paginate($perPage);

    //     // Transform items
    //     $transformedItems = $paginator->getCollection()->map(function ($transaction) use ($customerId) {

    //         // user details already available (no relationships needed)
    //         $transaction->payer_name  = $transaction->payer_name;
    //         $transaction->payer_email = $transaction->payer_email;
    //         $transaction->payer_mobile = $transaction->payer_mobile;

    //         $transaction->payee_name = $transaction->payee_name;
    //         $transaction->payee_email = $transaction->payee_email;
    //         $transaction->payee_mobile = $transaction->payee_mobile;

    //         // Perspective based transaction type
    //         if ($customerId) {

    //             if ($transaction->payee_id == $customerId && $transaction->type == RewardWallet::TYPE_CREDIT) {
    //                 $transaction->customer_transaction_type = 'CREDIT_RECEIVED';
    //                 $transaction->amount = $transaction->points;
    //                 $transaction->is_credit = true;
    //             } elseif ($transaction->payer_id == $customerId && $transaction->type == RewardWallet::TYPE_DEBIT) {
    //                 $transaction->customer_transaction_type = 'DEBIT_SENT';
    //                 $transaction->amount = -$transaction->points;
    //                 $transaction->is_credit = false;
    //             } else {
    //                 $transaction->customer_transaction_type = 'OTHER';
    //                 $transaction->amount = 0;
    //                 $transaction->is_credit = null;
    //             }

    //             // Counterparty information
    //             if ($transaction->payer_id == $customerId) {
    //                 $transaction->counterparty_id = $transaction->payee_id;
    //                 $transaction->counterparty_name = $transaction->payee_name;
    //                 $transaction->counterparty_email = $transaction->payee_email;
    //                 $transaction->counterparty_mobile = $transaction->payee_mobile;
    //                 $transaction->counterparty_type = 'RECEIVER';
    //             } else {
    //                 $transaction->counterparty_id = $transaction->payer_id;
    //                 $transaction->counterparty_name = $transaction->payer_name;
    //                 $transaction->counterparty_email = $transaction->payer_email;
    //                 $transaction->counterparty_mobile = $transaction->payer_mobile;
    //                 $transaction->counterparty_type = 'SENDER';
    //             }
    //         } else {
    //             // General ledger view
    //             $transaction->customer_transaction_type = $transaction->type;
    //             $transaction->amount = $transaction->type == RewardWallet::TYPE_CREDIT
    //                 ? $transaction->points
    //                 : -$transaction->points;
    //             $transaction->is_credit = $transaction->type == RewardWallet::TYPE_CREDIT;
    //             $transaction->counterparty_id = null;
    //             $transaction->counterparty_name = null;
    //             $transaction->counterparty_email = null;
    //             $transaction->counterparty_mobile = null;
    //             $transaction->counterparty_type = 'TRANSACTION';
    //         }

    //         return $transaction;
    //     });

    //     $paginator->setCollection($transformedItems);

    //     return $paginator;
    // }


    /**
     * Get agency-wise reward ledger
     */
    public function getAgencyLedger(int $agencyId, ?int $limit = null)
    {
        $query = RewardWallet::where('AgencyID', $agencyId)
            ->where('status', RewardWallet::STATUS_SUCCESS)
            ->with(['payer', 'payee'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Get agency reward summary
     */
    public function getRewardSummary(int $customer_id, int $agencyId): array
    {
        $totalCredits = RewardWallet::where('AgencyID', $agencyId)->where('customer_id', $customer_id)
            ->where('type', RewardWallet::TYPE_CREDIT)
            ->where('status', RewardWallet::STATUS_SUCCESS)
            ->sum('points');

        $totalDebits = RewardWallet::where('AgencyID', $agencyId)->where('customer_id', $customer_id)
            ->where('type', RewardWallet::TYPE_DEBIT)
            ->where('status', RewardWallet::STATUS_SUCCESS)
            ->sum('points');

        $netBalance = $totalCredits - $totalDebits;

        return [
            'total_credits' => $totalCredits,
            'total_debits' => $totalDebits,
            'total_rewardearn' => number_format(($netBalance), 2, '.', ''),
            'id' => $customer_id,
            'total_transactions' => RewardWallet::where('AgencyID', $agencyId)->where('customer_id', $customer_id)
                ->where('status', RewardWallet::STATUS_SUCCESS)
                ->count()
        ];
    }

    /**
     * Get transaction by reference
     */
    public function getTransactionByReference(string $txnRef)
    {
        return RewardWallet::where('ReferenceNo', $txnRef)
            ->with(['payer', 'payee'])
            ->get();
    }
    public function getDRTranByReference(string $txnRef, $AgencyID)
    {
        $latestEntry = RewardWallet::where('AgencyID', $AgencyID)->where('ReferenceNo', $txnRef)
            ->where('PlanType', 1)->where('type', 'DR')
            ->where('status', 'SUCCESS')->first();
        return $latestEntry ? $latestEntry->points : 0;
    }
}
