<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Helpers\Helper;
use App\Models\LoyaltyUserCard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Models\RewardEarn;
use App\Models\StorePaymentTransactions;
use App\Models\StoreSettlement;
use App\Models\userDevices;
use App\Services\RewardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ScanPayController extends Controller
{

    public $APP_NAME;
    public $APP_URL;
    public $PaymentSecretPwd;
    public $PaymentMerchantId;
    public $SECURITYKEY;
    private $rewardService;

    private $merchantKey;
    private $salt;

    public function __construct(RewardService $rewardService)
    {
        $this->APP_URL = env('APP_URL');
        $this->APP_NAME = env('APP_NAME');
        $this->PaymentMerchantId = env('PaymentMerchantId');
        $this->PaymentSecretPwd = env('PaymentSecretPwd');
        $this->SECURITYKEY = env('SECURITYKEY');
        $this->rewardService = $rewardService;
        $this->merchantKey = env('EASEBUZZ_MERCHANT_KEY');
        $this->salt = env('EASEBUZZ_SALT');
    }
    public function scanQrcode(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $validator = Validator::make($request->all(), [
                    'payee_id' => 'required|exists:users,id'
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
                            'httpStatus' => 201,
                        ],
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                }
                $User = $request->user();
                $payee_id = $request->payee_id;
                $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
                $result = User::without('details')->select(
                    'users.id',
                    'users.name',
                    'users.mobile',
                    'users.countrycode',
                    'users.email',
                )->where('users.AgencyID', $AgencyID)->where('users.id', '=', $payee_id)->where('users.active', '=', 1)->first();
                if ($User && $result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => $result,
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 500,
                        ],
                        'message' => 'Oops something went wrong',
                    ]);
                }
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }
    public function scanPay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payee_id' => 'required|exists:users,id',
            'points' => 'required|numeric|min:1',
            // 'PassCode'    => 'required|digits:6',
            // 'device_id' => 'required|string',
            'description' => 'nullable|string'
        ]);
        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        $UserSysId = $request->user()->id;
        // $validator->after(function ($validator) use ($request, $AgencyID) {
        //     $user = User::where('AgencyID', $AgencyID)->find($request->user()->id);
        //     if (!$user || !Hash::check($request->PassCode, $user->WalletPassCode)) {
        //         $validator->errors()->add('PassCode', 'The provided PassCode is incorrect.');
        //     }
        // });
        // $validator->after(function ($validator) use ($request, $AgencyID) {
        //     $Devices = userDevices::where('AgencyID', $AgencyID)->where('user_id', $request->user()->id)->where('device_id', $request->device_id)->exists();
        //     if (!$Devices) {
        //         $validator->errors()->add('device_id', 'Unknown device. please verify device.');
        //     }
        // });
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
                    'httpStatus' => 201,
                ],
                'message' => implode(',', $errorArray),
                'error' => $validator->messages(),
            ]);
        }

        $user = auth()->user();
        $payee_id = $request->payee_id;
        $points = $request->points;
        $description = $request->description;
        // $points_to_redeem = RewardEarn::availableForRedemption($user)->get()->sum('rewardearn');
        $RewardSummary = $this->rewardService->getRewardSummary($user->id, $AgencyID);
        $points_to_redeem = isset($RewardSummary['total_rewardearn']) ? round($RewardSummary['total_rewardearn'], 2) : 0;
        $selectedUser = User::where('AgencyID', $AgencyID)->where('id', $payee_id)->first();
        $txn_ref = StorePaymentTransactions::generateTxnRef();
        $UserCardDetails = LoyaltyUserCard::getUserCardDetailsWithReward($selectedUser);

        // pr($request->all());

        // pr($payee_id);
        // pr($UserSysId);
        // die;
        $RewardRequest = [
            "points_to_redeem" => $points,
            "notes" => !empty($description) ? $description : 'Payment to store ID ' . $payee_id,
        ];
        if ($points_to_redeem < $points) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 400,
                ],
                'message' => 'Insufficient reward points'
            ]);
        }

        DB::beginTransaction();
        try {
            // $redemption = Helper::rewardRedemption($user, $RewardRequest);
            // Helper::rewardprocessRedemption($redemption);
            // Log transaction
            $txn = StorePaymentTransactions::create([
                'txn_ref' => $txn_ref,
                'AgencyID' => $AgencyID,
                'payer_id' => $UserSysId,
                'payee_id' => $payee_id,
                'type' => 'PAY',
                'points' => $points,
                'status' => 'SUCCESS',
                'description' => !empty($description) ? $description : 'Payment to User ID ' . $payee_id,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'device_id' => $request->header('Device-ID'),
                'ip_address' => $request->ip(),
            ]);

            // Add to store settlement
            $settlement = StoreSettlement::firstOrCreate(
                ['payee_id' => $payee_id, 'AgencyID' => $AgencyID, 'settlement_date' => now()->toDateString()],
                ['total_points' => 0]
            );
            $settlement->increment('total_points', $points);
            // if ($UserCardDetails) {
            //     Helper::RewardsEarning($selectedUser, $UserCardDetails, 0, $points, $txn_ref, 1);
            // } else {
            //     Helper::RewardsEarningTemp($selectedUser, 0, $points, $txn_ref, 1);
            // }

            $RewardRequest = [
                "points" => ceil($points),
                "description" =>  !empty($description) ? $description : 'Transfer to User ID ' . $payee_id,
                'AgencyID' => $AgencyID,
                'UserSysId' =>  $request->user()->id,
                "payer_id" => $UserSysId,
                "payee_id" => $payee_id,
                "RewardMode" => "Pay",
                "ReferenceNo" => $request->BookingID,
                'PlanType' => 4,
            ];
            $redemption = $this->rewardService->transferPoints($RewardRequest);
            $success = isset($redemption['success']) ? $redemption['success'] : 0;
            $message = isset($redemption['message']) ? $redemption['message'] : '';
            if ($success != 1) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 2002,
                    ],
                    'message' => $message,
                    'transaction' => []
                ]);
            }
            DB::commit();
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Payment successful',
                'transaction' => $txn
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => 'Payment failed',
                'error' => $e->getMessage()
            ]);
        }
    }


    public function getPaymentHistory(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $post['stores_id'] = 0;
                $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                $post['AgencyID'] = $AgencyID;
                $post['customer_id'] = (isset($request->customer_id) && $request->customer_id > 0) ? $request->customer_id : $user->id;

                $result = $this->rewardService->getCustomerLedger($post['customer_id'] ?? null, $post['AgencyID'], $perPage);
                //$result = StorePaymentTransactions::getPaymentHistory($user, $perPage, $post);
                if ($user && $result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => $result,
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 500,
                        ],
                        'message' => 'Oops something went wrong',
                    ]);
                }
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }
    public function getUserBalance(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $user = $request->user();
                $post['stores_id'] = 0;
                $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                $post['AgencyID'] = $AgencyID;
                $result = $this->rewardService->getRewardSummary($user->id, $AgencyID);
                // $result = StorePaymentTransactions::getUserBalance($user, $post);
                if ($user && $result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => $result,
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 500,
                        ],
                        'message' => 'Oops something went wrong',
                    ]);
                }
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }
}
