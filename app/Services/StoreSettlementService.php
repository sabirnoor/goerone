<?php

namespace App\Services;

use App\Models\LoyaltyRedemption;
use App\Models\StoreSettlementPayment;
use App\Models\StoreSettlements;
use App\Models\WalletModel;
use Illuminate\Support\Facades\DB;

class StoreSettlementService
{
    public function markPaid(
        int $settlementId,
        object $user,
        string $paymentMode,
        string $referenceNo,
        string $remarks
    ) {
        try {
            $settlement = StoreSettlements::findOrFail($settlementId);
            // pr($settlement);
            // die;
            $WalletBalance = WalletModel::WalletBalance($user, $settlement->customer_id);
            $wallet_balance = isset($WalletBalance['balances']['wallet_balance']) ? round($WalletBalance['balances']['wallet_balance'], 2) : 0;

            if ($wallet_balance < (round($settlement->payable_amount, 2))) {
                return [
                    'status' => [
                        'success' => false,
                        'httpStatus' => 1015,
                    ],
                    'message' => "Insufficient balance : Please contact the Business Team to topup the account.",
                ];
            }
            $wallet = [
                "customer_id" => $settlement->customer_id,
                "amount" => $settlement->payable_amount,
                "RefrenceNo" => $referenceNo,
                "PlanType" => 10,
                "Remark" => $remarks ?? 'Settlement Done',
                'PaymentMode' => 'Paid For Order'
            ];
            $status = true;
            if ((round($settlement->payable_amount, 2)) > 0) {
                $WalletBook = WalletModel::bookingUsingWalletBalance($user, $wallet);
                $status = isset($WalletBook['status']['success']) ? $WalletBook['status']['success'] : 0;
            }
            if ($status == 0) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 1015,
                    ],
                    'message' => "Unable to debit from wallet. please try again.",
                ]);
            }

            DB::transaction(function () use ($settlement, $paymentMode, $referenceNo, $remarks, $user) {
                $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                StoreSettlementPayment::create([
                    'AgencyID' => $AgencyID,
                    'UserSysId' => $user->id,
                    'settlement_id' => $settlement->id,
                    'amount' => $settlement->payable_amount,
                    'payment_mode' => $paymentMode,
                    'reference_no' => $referenceNo,
                    'remarks' => $remarks,
                    'payment_date' => now()
                ]);

                $settlement->update([
                    'status' => 'paid',
                    'settled_by' => $user->id,
                    'settled_at' => now()
                ]);

                LoyaltyRedemption::where(
                    'settlement_id',
                    $settlement->id
                )->update([
                    'settlement_status' => 'settled'
                ]);
            });

            return [
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Settlement Paid Successfully',
                'data' => $settlement->fresh()
            ];
        } catch (\Exception $e) {
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }
    public function partialPayment(
        int $settlementId,
        float $amount,
        string $paymentMode,
        string $referenceNo,
        string $remarks
    ) {
        $settlement = StoreSettlements::findOrFail($settlementId);
        StoreSettlementPayment::create([
            'settlement_id' => $settlementId,
            'amount' => $amount,
            'payment_mode' => $paymentMode,
            'reference_no' => $referenceNo,
            'remarks' => $remarks,
            'payment_date' => now()
        ]);

        $paid = $settlement->payments()->sum('amount');

        if ($paid >= $settlement->payable_amount) {
            $settlement->update([
                'status' => 'paid',
                'settled_at' => now()
            ]);

            LoyaltyRedemption::where(
                'settlement_id',
                $settlement->id
            )->update([
                'settlement_status' => 'settled'
            ]);
        } else {

            $settlement->update([
                'status' => 'partial'
            ]);
        }

        return $settlement->fresh();
    }

    public function bulkPaid(
        array $settlementIds,
        object $user,
        string $paymentMode,
        string $referenceNo,
        string $remarks
    ) {
        try {
            DB::transaction(function () use ($settlementIds, $paymentMode, $referenceNo, $remarks, $user) {
                $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                $settlements = StoreSettlements::whereIn(
                    'id',
                    $settlementIds
                )->where('status', 'pending')->get();

                foreach ($settlements as $settlement) {

                    $WalletBalance = WalletModel::WalletBalance($user, $settlement->customer_id);
                    $wallet_balance = isset($WalletBalance['balances']['wallet_balance']) ? round($WalletBalance['balances']['wallet_balance'], 2) : 0;

                    if ($wallet_balance < (round($settlement->payable_amount, 2))) {
                        return [
                            'status' => [
                                'success' => false,
                                'httpStatus' => 1015,
                            ],
                            'message' => "Insufficient balance : Please contact the Business Team to topup the account.",
                        ];
                    }
                    $wallet = [
                        "customer_id" => $settlement->customer_id,
                        "amount" => $settlement->payable_amount,
                        "RefrenceNo" => $referenceNo,
                        "PlanType" => 10,
                        "Remark" => $remarks ?? 'Settlement Done',
                        'PaymentMode' => 'Paid For Order'
                    ];
                    $status = true;
                    if ((round($settlement->payable_amount, 2)) > 0) {
                        $WalletBook = WalletModel::bookingUsingWalletBalance($user, $wallet);
                        $status = isset($WalletBook['status']['success']) ? $WalletBook['status']['success'] : 0;
                    }
                    if ($status == 0) {
                        return response()->json([
                            'status' => [
                                'success' => false,
                                'httpStatus' => 1015,
                            ],
                            'message' => "Unable to debit from wallet. please try again.",
                        ]);
                    }

                    StoreSettlementPayment::create([
                        // 'settlement_id' => $settlement->id,
                        // 'amount' => $settlement->payable_amount,
                        // 'payment_mode' => $paymentMode,
                        // 'payment_date' => now(),

                        'AgencyID' => $AgencyID,
                        'UserSysId' => $user->id,
                        'settlement_id' => $settlement->id,
                        'amount' => $settlement->payable_amount,
                        'payment_mode' => $paymentMode,
                        'reference_no' => $referenceNo,
                        'remarks' => $remarks,
                        'payment_date' => now()
                    ]);
                    $settlement->update([
                        'status' => 'paid',
                        'settled_by' => $user->id,
                        'settled_at' => now(),
                    ]);

                    LoyaltyRedemption::where(
                        'settlement_id',
                        $settlement->id
                    )->update([
                        'settlement_status' => 'settled',
                        'settlement_amount' => ($settlement->payable_amount / $settlement->redem_count),
                        'settlement_by' => $user->id,
                    ]);
                }
            });
            return [
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Settlement Paid Successfully',
            ];
        } catch (\Exception $e) {
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }
}
