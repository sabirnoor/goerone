<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Voucher\VoucherOrderResource;
use App\Models\VoucherPayment;
use App\Models\WalletModel;
use App\Services\Voucher\Gateways\VoucherPaymentGateway;
use App\Services\Voucher\VoucherPurchaseService;
use Illuminate\Http\Request;
use App\Services\RewardService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class VoucherCheckoutController extends Controller
{
    public function __construct(private VoucherPaymentGateway $gateway, private VoucherPurchaseService $purchase, private RewardService $rewardService) {}

    public function checkout(Request $request)
    {
        $result = $this->purchase->checkout($request->user());

        return response()->json([
            'status'           => true,
            'payment_required' => $result['payment_required'],
            'order'            => new VoucherOrderResource($result['order']),
            'payment'          => $result['payment'], // gateway data for the frontend to open the payment page
        ]);
    }
    public function purchase(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'atomTxnId' => 'required',
            'statusCode' => 'required',
            'amount' => 'required|integer|min:1',
            'merchant_txn_id' => 'required|exists:voucher_payments,merchant_txn_id',

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
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 422,
                ],
                'message' => implode(',', $errorArray),
                'error' => $validator->errors()
            ]);
        }
        $payment = VoucherPayment::where('merchant_txn_id',  $request->merchant_txn_id)->lockForUpdate()->firstOrFail();

        $request->merge(['merchTxnId' => $request->merchant_txn_id]);
        $request->merge(['atomTxnId' => $request->atomTxnId]);
        $request->merge(['statusCode' => $request->statusCode]);
        $request->merge(['amount' => $payment->amount ?? 0]);
        $user = $request->user();
        $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
        $UserSysId = $request->user()->id;
        $usereward = $request->usereward ?? 0;
        $BookingAmount = ($payment->amount ?? 0);
        if ($usereward == 1) {
            $RewardSummary = $this->rewardService->getRewardSummary($user->id, $AgencyID);
            $points_to_redeem = isset($RewardSummary['total_rewardearn']) ? $RewardSummary['total_rewardearn'] : 0;
            if ($points_to_redeem > $BookingAmount) {
                $points_to_redeem = $BookingAmount;
            }

            $RewardRequest = [
                "points" => ($points_to_redeem),
                "description" => 'Used on Voucher Purchase',
                'AgencyID' => ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID,
                'UserSysId' =>  $request->user()->id,
                "payer_id" => $user->id,
                "payee_id" => $AgencyID,
                "RewardMode" => "Pay",
                "ReferenceNo" => $request->atomTxnId,
                'PlanType' => 12,
            ];
            if ($points_to_redeem > 0) {
                $redemption = $this->rewardService->transferPoints($RewardRequest);
                $success = $redemption['success'] ?? 0;
                $message = isset($redemption['message']) ? $redemption['message'] : 0;
                if ($success == 1) {
                    $BookingAmount = ($BookingAmount - ($points_to_redeem));
                }
                if ($success == 0) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 1016,
                        ],
                        'message' => $message,
                    ]);
                }
            }
        }

        $wallet = [
            "customer_id" => $user->id,
            "amount" => $BookingAmount,
            "RefrenceNo" => isset($request->atomTxnId) ? $request->atomTxnId : date('YmdHis'),
            "PlanType" => 12,
            "Remark" => "Used on Voucher Purchase",
            'PaymentMode' => 'Voucher Purchase'
        ];
        $WalletBook = WalletModel::bookingUsingWalletBalance($user, $wallet);
        $status = isset($WalletBook['status']['success']) ? $WalletBook['status']['success'] : 0;
        $message = isset($WalletBook['message']) ? $WalletBook['message'] : 0;
        if ($status == 0) {
            DB::rollback();
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 1015,
                ],
                'message' => $message,
            ]);
        }
        $order = null;
        try {
            $order = $this->purchase->handleGatewayResult($this->gateway->parseCallback($request));
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'order'            => $order,
            ]);
        } catch (Throwable $e) {
            Log::error('Voucher payment callback failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'order'            => null,
                'message'            => $e->getMessage(),
            ]);
        }
    }
}
