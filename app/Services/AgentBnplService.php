<?php

// app/Services/AgentBnplService.php
namespace App\Services;

use App\Models\AgentBnplCreditLimit;
use App\Models\AgentBnplTransaction;
use Illuminate\Support\Facades\DB;

class AgentBnplService
{
    /**
     * Debit from agent's BNPL credit
     *
     * @param int $agentId
     * @param float $amount
     * @param string|null $referenceId
     * @param string|null $description
     * @return array
     * @throws \Exception
     */
    public function debitCredit($user, $agentId, $amount, $referenceId = null, $description = null)
    {
        return DB::transaction(function () use ($user, $agentId, $amount, $referenceId, $description) {
            $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
            $creditLimit = AgentBnplCreditLimit::where('AgencyID', $AgencyID)->where('agent_id', $agentId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($creditLimit->available_credit < $amount) {
                throw new \Exception('Insufficient credit available');
            }

            // Update credit limit
            $balanceBefore = $creditLimit->available_credit;
            $creditLimit->available_credit -= $amount;
            $creditLimit->used_credit += $amount;
            $creditLimit->AgencyID = $AgencyID;
            $creditLimit->UserSysId = $user->id;
            $creditLimit->save();

            // Record transaction
            $transaction = AgentBnplTransaction::create([
                'AgencyID' => $AgencyID,
                'UserSysId' => $user->id,
                'agent_id' => $agentId,
                'credit_limit_id' => $creditLimit->id,
                'transaction_type' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $creditLimit->available_credit,
                'reference_id' => $referenceId,
                'description' => $description ?? 'BNPL booking debit',
            ]);

            return [
                'success' => true,
                'credit_limit' => $creditLimit,
                'transaction' => $transaction,
            ];
        });
    }

    /**
     * Credit back to agent's BNPL account
     *
     * @param int $agentId
     * @param float $amount
     * @param string|null $referenceId
     * @param string|null $description
     * @return array
     * @throws \Exception
     */
    public function creditBack($user, $agentId, $amount, $referenceId = null, $description = null, $currency = 'INR')
    {
        return DB::transaction(function () use ($user, $agentId, $amount, $referenceId, $description, $currency) {
            $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
            $creditLimit = AgentBnplCreditLimit::where('AgencyID', $AgencyID)->where('agent_id', $agentId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($creditLimit->used_credit <= 0) {
                throw new \Exception('No used credit available to credit back');
            }

            // Validate that amount doesn't exceed used credit
            if ($amount > $creditLimit->used_credit) {
                throw new \Exception(
                    'Credit back amount (' . $amount . ') exceeds used credit (' . $creditLimit->used_credit . ')'
                );
            }
            // Update credit limit
            $balanceBefore = $creditLimit->available_credit;
            $creditLimit->available_credit += $amount;
            $creditLimit->used_credit -= $amount;

            // Ensure used credit doesn't go negative
            if ($creditLimit->used_credit < 0) {
                $creditLimit->used_credit = 0;
            }

            $creditLimit->AgencyID = $AgencyID;
            $creditLimit->UserSysId = $user->id;
            $creditLimit->save();

            // Record transaction
            $transaction = AgentBnplTransaction::create([
                'AgencyID' => $AgencyID,
                'UserSysId' => $user->id,
                'agent_id' => $agentId,
                'credit_limit_id' => $creditLimit->id,
                'transaction_type' => 'credit',
                'amount' => $amount,
                'currency' => $currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $creditLimit->available_credit,
                'reference_id' => $referenceId,
                'description' => $description ?? 'BNPL credit back',
            ]);

            return [
                'success' => true,
                'credit_limit' => $creditLimit,
                'transaction' => $transaction,
            ];
        });
    }

    public function initializeCreditLimit($user, $agentId, $totalLimit, $currency = 'INR', $TermsDays = 1, $Notes = NULL)
    {
        return DB::transaction(function () use ($user, $agentId, $totalLimit, $currency, $TermsDays, $Notes) {
            $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
            // Check if already exists
            if (AgentBnplCreditLimit::where('AgencyID', $AgencyID)->where('agent_id', $agentId)->exists()) {
                throw new \Exception("Credit limit already exists for agent ID: {$agentId}");
            }

            $creditLimit = AgentBnplCreditLimit::create([
                'AgencyID' => $AgencyID,
                'UserSysId' => $user->id,
                'agent_id' => $agentId,
                'total_credit_limit' => $totalLimit,
                'available_credit' => $totalLimit,
                'used_credit' => 0,
                'currency' => $currency,
                'TermsDays' => $TermsDays,
                'Notes' => $Notes,
                'is_active' => true
            ]);

            return $creditLimit;
        });
    }

    public function increaseCreditLimit($user, $agentId, $additionalAmount, $reason = 'Credit limit increase')
    {
        return DB::transaction(function () use ($user, $agentId, $additionalAmount, $reason) {
            $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
            // Lock the record for update to prevent concurrent modifications
            $creditLimit = AgentBnplCreditLimit::where('AgencyID', $AgencyID)->where('agent_id', $agentId)
                ->lockForUpdate()
                ->firstOrFail();

            // Store current values for audit
            $oldLimit = $creditLimit->total_credit_limit;
            $oldAvailable = $creditLimit->available_credit;

            // Update the credit limit
            $creditLimit->total_credit_limit += $additionalAmount;
            $creditLimit->available_credit += $additionalAmount;
            $creditLimit->save();

            // Record this as a special transaction
            AgentBnplTransaction::create([
                'AgencyID' => $AgencyID,
                'UserSysId' => $user->id,
                'agent_id' => $agentId,
                'credit_limit_id' => $creditLimit->id,
                'transaction_type' => 'credit',
                'amount' => $additionalAmount,
                'balance_before' => $oldAvailable,
                'balance_after' => $creditLimit->available_credit,
                'reference_id' => 'limit_increase_' . time(),
                'description' => $reason .
                    ' | Old limit: ' . $oldLimit .
                    ' | New limit: ' . $creditLimit->total_credit_limit,
            ]);

            return [
                'success' => true,
                'old_limit' => $oldLimit,
                'new_limit' => $creditLimit->total_credit_limit,
                'transaction_id' => $transaction->id ?? null,
            ];
        });
    }

    /**
     * Get agent's current credit limit information
     *
     * @param int $agentId
     * @return AgentBnplCreditLimit
     */
    public function getCreditLimit($user, $agentId)
    {
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        // return AgentBnplCreditLimit::where('agent_id', $agentId)->where('AgencyID', $AgencyID)
        //     ->where('is_active', true)
        //     ->first();
        return AgentBnplCreditLimit::join('users', 'users.id', '=', 'agent_bnpl_creditlimit.agent_id')
            ->where('agent_bnpl_creditlimit.agent_id', $agentId)
            ->where('agent_bnpl_creditlimit.AgencyID', $AgencyID)
            ->where('agent_bnpl_creditlimit.is_active', true)
            ->where('users.BNPLCreditStatus', true)
            ->select('agent_bnpl_creditlimit.*')
            ->first();
    }

    /**
     * Get agent's transaction history
     *
     * @param int $agentId
     * @param int $limit
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getTransactionHistory($agentId, $limit = 20)
    {
        return AgentBnplTransaction::where('agent_id', $agentId)
            ->with('creditLimit')
            ->orderBy('created_at', 'desc')
            ->paginate($limit);
    }


    public function getAgentLedger($user, $agentId, array $filters = [])
    {
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        // $query = AgentBnplTransaction::where('AgencyID', $AgencyID)->orderBy('created_at', 'desc');
        $query = AgentBnplTransaction::where('agent_bnpl_transactions.AgencyID', $AgencyID)
            ->leftJoin('users', 'agent_bnpl_transactions.agent_id', '=', 'users.id')
            ->select('agent_bnpl_transactions.*', 'users.UserType', 'users.email', 'users.name', 'users.mobile', 'users.countrycode') // Add other columns you need from users table
            ->orderBy('agent_bnpl_transactions.created_at', 'desc');
        // $query = AgentBnplTransaction::with('creditLimit')->where('AgencyID', $AgencyID)->orderBy('created_at', 'desc');

        // Apply date filters if provided
        if (!empty($agentId) && $agentId > 0) {
            $query->where('agent_bnpl_transactions.agent_id', $agentId);
        }
        if (!empty($filters['start_date'])) {
            $query->whereDate('agent_bnpl_transactions.created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->whereDate('agent_bnpl_transactions.created_at', '<=', $filters['end_date']);
        }

        // Filter by transaction type if provided
        if (!empty($filters['type']) && in_array($filters['type'], ['credit', 'debit'])) {
            $query->where('agent_bnpl_transactions.transaction_type', $filters['type']);
        }

        // Apply pagination limit
        $limit = $filters['per_page'] ?? 50;

        return $query->paginate($limit);
    }

    public function sumOfCreditLimit($user, $agentId = 0)
    {
        return DB::transaction(function () use ($user, $agentId) {
            $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
            $sums = AgentBnplCreditLimit::select(
                DB::raw('SUM(agent_bnpl_creditlimit.total_credit_limit) as total_credit_sum'),
                DB::raw('SUM(agent_bnpl_creditlimit.available_credit) as available_credit_sum'),
                DB::raw('SUM(agent_bnpl_creditlimit.used_credit) as used_credit_sum')
            )->join('users as u', 'u.id', '=', 'agent_bnpl_creditlimit.agent_id')
                ->where(function ($query) use ($agentId) {
                    if ($agentId > 0) {
                        $query->where('agent_bnpl_creditlimit.agent_id', $agentId);
                    }
                })->where('u.BNPLCreditStatus', true)->where('agent_bnpl_creditlimit.AgencyID', $AgencyID)->where('agent_bnpl_creditlimit.is_active', true)->first();

            return [
                'total_credit_sum' => (float)$sums->total_credit_sum ?? 0,
                'available_credit_sum' => (float)$sums->available_credit_sum ?? 0,
                'used_credit_sum' => (float)$sums->used_credit_sum ?? 0
            ];
        });
    }
}
