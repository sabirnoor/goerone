<?php

namespace App\Services;

use App\Models\User;
use App\Models\Transaction;
use App\Models\ServiceRate;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function chargeUser(User $user, string $serviceType, float $units = 1, $reference = null)
    {
        $rate = ServiceRate::where('service_type', $serviceType)->first();

        if (!$rate) {
            throw new \Exception("Service rate not found for {$serviceType}");
        }
        if ($user->UserType != 1) {
            $AgencyID = (isset($user->AgencyID) && !empty($user->AgencyID)) ? $user->AgencyID : $user->id;
            $user = User::find($AgencyID);
        }
        $amount = $rate->rate * $units;

        if (!$user->hasSufficientBalance($amount)) {
            throw new \Exception("Insufficient balance");
        }

        return DB::transaction(function () use ($user, $serviceType, $amount, $reference) {
            $balanceBefore = $user->balance;
            $user->decrement('balance', $amount);
            $balanceAfter = $user->balance;

            $transaction = new Transaction([
                'service_type' => $serviceType,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => "Charge for {$serviceType} service",
            ]);

            if ($reference) {
                $transaction->reference_type = get_class($reference);
                $transaction->reference_id = $reference->id;
            }

            $user->transactions()->save($transaction);

            return $transaction;
        });
    }

    public function topUpUser(User $user, float $amount, string $description = 'Account top up')
    {
        return DB::transaction(function () use ($user, $amount, $description) {
            $balanceBefore = $user->balance;
            $user->increment('balance', $amount);
            $balanceAfter = $user->balance;

            $transaction = new Transaction([
                'service_type' => 'topup',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
            ]);

            $user->transactions()->save($transaction);

            return $transaction;
        });
    }
}
