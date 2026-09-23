<?php

namespace App\Http\Controllers\API;

use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use App\Models\incorporation_details;
use App\Models\Invoices;
use App\Models\invoices_items;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyUserCard;
use App\Models\mst_currency;
use App\Models\mst_items;
use App\Models\mst_markups;
use App\Models\RewardEarn;
use App\Models\Store;
use App\Models\StoresMapping;
use DateTime;
use App\Models\Vouchers;
use App\Models\WalletModel;
use App\Services\OtpService;
use App\Services\RewardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Mpdf\Mpdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LoyaltyController extends Controller
{

    public $APP_URL;
    public $APP_NAME;
    public $API_URL;
    protected $otpService;
    private $rewardService;
    public function __construct(OtpService $otpService, RewardService $rewardService)
    {
        $this->APP_NAME = env('APP_NAME');
        $this->APP_URL = env('APP_URL');
        $this->API_URL = env('API_URL');
        $this->otpService = $otpService;
        $this->rewardService = $rewardService;
    }

    public function redeemReward(Request $request)
    {
        $user = $request->user();
        $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;

        $validator = Validator::make($request->all(), [
            'order_amount' => 'required|integer|min:1',
            'membershipId' => 'nullable|integer||exists:loyalty_program,program_id',
            'reward_id' => [
                'required',
                'integer',
                Rule::exists(LoyaltyReward::class, 'reward_id')
                    ->where(fn($q) => $q->where('AgencyID', $AgencyID))
            ],
            'store_id' => [
                'required',
                'integer',
                Rule::exists(Store::class, 'id')
                    ->where(fn($q) => $q->where('AgencyID', $AgencyID))
            ]

        ], [
            'amount.min' => 'The discount amount must be greater than 1.',
            'store_id' => 'Selected store is not found',
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
        DB::beginTransaction();
        try {
            if (isset($request->membershipId) && $request->membershipId > 0) {
                $LoyaltyProgram = LoyaltyProgram::getMembershipDetails($request->membershipId);
                $checkUserCardExist = LoyaltyUserCard::select('user_card.*', 'loyalty_card.program_id')
                    ->leftjoin('loyalty_card', 'loyalty_card.card_id', '=', 'user_card.card_id')
                    ->where('user_card.AgencyID', $user->AgencyID)->where('user_card.user_id', $request->user()->id)
                    ->where('user_card.status', 'active')->first();
                $program_id = isset($checkUserCardExist->program_id) ? $checkUserCardExist->program_id : 0;

                if ($program_id === 0) {
                    $post['cardNumbers'] = [];
                    $post['keyword'] = '';
                    $post['program_id'] = $request->membershipId ?? 0;
                    $UnAssignloyaltycard = LoyaltyCard::getUnAssignloyaltycardAuto($user, 25, $post);
                    $card_id = isset($UnAssignloyaltycard[0]['card_id']) ? $UnAssignloyaltycard[0]['card_id'] : 0;
                    $currentDate = Carbon::now()->format('Y-m-d H:i:s');
                    $twoMonthsLater = Carbon::now()->addMonths(12)->format('Y-m-d H:i:s');
                    $arrGSTOnAgencyFixMarkUp = $this->calculateServiceTax($LoyaltyProgram->membership_amount, 18);
                    $TotalAmounts = ($arrGSTOnAgencyFixMarkUp['serviceTaxAmount'] + ($LoyaltyProgram->membership_amount ?? 0));

                    $TotalAmount = $TotalAmounts;
                    $SubTotal = $LoyaltyProgram->membership_amount ?? 0;
                    $DiscountSubTotal = $LoyaltyProgram->membership_amount ?? 0;
                    $TotalTaxAmount = $arrGSTOnAgencyFixMarkUp['serviceTaxAmount'] ?? 0;
                    $ItemName = $LoyaltyProgram->program_name ?? 'Membership';
                    $Itemid = 0;
                    $mst_items = mst_items::where('name', 'like', '%' . $ItemName . '%')->where(function ($q) use ($AgencyID) {
                        $q->whereNull('AgencyID')
                            ->orWhere('AgencyID', $AgencyID);
                    })->first();

                    if ($TotalAmount > 0) {
                        $wallet = [
                            "customer_id" => $user->id,
                            "amount" => $TotalAmount,
                            "RefrenceNo" => isset($request->RefrenceNo) ? $request->RefrenceNo : date('YmdHis'),
                            "PlanType" => 4,
                            "Remark" => "VIP Membership",
                            'PaymentMode' => $ItemName
                        ];
                        $WalletBook = WalletModel::bookingUsingWalletBalance($user, $wallet);
                        $currentDate = Carbon::now()->format('Y-m-d H:i:s');
                        Storage::disk('public')->put('logs/membership/' . $currentDate . '_walletDebit.json', json_encode($WalletBook));
                        $status = isset($WalletBook['status']['success']) ? $WalletBook['status']['success'] : 0;
                        $message = isset($WalletBook['message']) ? $WalletBook['message'] : 0;
                        if ($status == 0) {
                            return response()->json([
                                'status' => [
                                    'success' => false,
                                    'httpStatus' => 1015,
                                ],
                                'message' => $message,
                            ]);
                        }
                    }

                    if ($card_id == 0) {
                        $card_number = generateCardNumber($user->AgencyID);
                        $CreateNewcard = [
                            'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                            'UserSysId' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                            "program_id" => $request->membershipId ?? 0,
                            "status" => "active",
                            "card_number" => $card_number,
                            "card_type" => "premium",
                            "issue_date" => $currentDate,
                            "initial_points" => "0",
                            "expiration_date" => $twoMonthsLater
                        ];
                        $card_id = LoyaltyCard::insertGetId($CreateNewcard);
                    }
                    $cardDetails = LoyaltyCard::getcardDetails($user, $card_id);
                    $card_number = LoyaltyUserCard::createUniqueCardNumber($user);

                    $MasterCardNo = (str_replace(' ', '', ($cardDetails->card_number)));

                    $CreateData = [
                        'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                        'UserSysId' => $request->user()->id,
                        'user_id' => $user->id,
                        'card_id' => $card_id,
                        'points_balance' => 0,
                        'status' => 'active',
                        'card_number' => $MasterCardNo,
                        // 'card_number' => $MasterCardNo . $card_number['card_no'],
                        'card_no' => $card_number['card_no'],
                        'is_primary_card' => true,
                        'notes' => 'Auto Assign',
                        'activation_date' => $currentDate,
                        'deactivation_date' => $twoMonthsLater
                    ];
                    if (empty($mst_items)) {
                        $Insert = array(
                            'AgencyID' => $AgencyID,
                            'UserSysId' => $request->user()->id,
                            'types' => 1,
                            'name' => $ItemName,
                            'HSNCode' => 998599,
                            'TaxPreference' => 1,
                            'SellingPrice' => $SubTotal,
                            'Description' => 'GoerOne Membership ' . $ItemName,
                            'taxrate' => 18,
                            'active' => 1,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        );
                        $Itemid = mst_items::insertGetId($Insert);
                    } else {
                        $Itemid = $mst_items->id ?? 0;
                    }
                    $currentDateTime = new DateTime('now');
                    $currentDate = $currentDateTime->format('Y-m-d');
                    $currentDateNote = $currentDateTime->format('d/m/Y');
                    $InvoiceNo = Invoices::generateInvoiceNo(($user->UserType == 1) ? $user->id : $user->AgencyID);
                    $notes = 'Dear Customer,<br>
                        I hope this message finds you well.<br><br>
                        This is a friendly reminder to request that the payment for invoice ' . $InvoiceNo . ' be made before the due date of ' . $currentDateNote . '. Prompt payment would be greatly appreciated and will help us continue providing you with excellent service.<br><br>
                        Please let us know if you have any questions or if there are any issues we can assist with.  <br><br>
                        Thank you for your attention to this matter.';

                    $mst_currency = mst_currency::where("name", "LIKE", "%" . trim(($request->currency ?? 'INR')) . "%")->first();
                    $checkMarkup = mst_markups::where('AgencyID', $user->AgencyID)->where('parent_id', 0)
                        ->where('upgrade_vip', 1)->first();

                    $InvoiceInsert['AgencyID'] = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                    $InvoiceInsert['UserSysId'] = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                    $InvoiceInsert['customer_id'] = $user->id;
                    $InvoiceInsert['TPSystemID'] = 0;
                    $InvoiceInsert['DueOnReceipt'] = 0;
                    $InvoiceInsert['InvoiceCurrency'] = (isset($mst_currency->id) && !empty($mst_currency->id)) ? $mst_currency->id : 1;
                    $InvoiceInsert['tdstcsApplied'] = 0;
                    $InvoiceInsert['invoiceNo'] = $InvoiceNo;
                    $InvoiceInsert['SupplierState'] = !empty($request->user()->details->mst_state_id) ? $request->user()->details->mst_state_id : 0;
                    $InvoiceInsert['InvoiceDueDate'] = date('Y-m-d H:i:s');
                    $InvoiceInsert['InvoiceDate'] = date('Y-m-d');
                    $InvoiceInsert['TotalTds'] = 0;
                    $InvoiceInsert['tds_taxes'] = 0;
                    $InvoiceInsert['tdstaxid'] = 0;
                    $InvoiceInsert['Notes'] = $notes;
                    $InvoiceInsert['TermsCondition'] = '';
                    $InvoiceInsert['discount'] = 0; //isset($data['discount']) ? $data['discount'] : 0;
                    $InvoiceInsert['TotalDiscount'] = 0; //isset($IntTotalDiscount) ? $IntTotalDiscount : 0;
                    $InvoiceInsert['SubTotal'] = isset($SubTotal) ? $SubTotal : 0;
                    $InvoiceInsert['TotalTaxAmount'] = isset($TotalTaxAmount) ? $TotalTaxAmount : 0;
                    $InvoiceInsert['TotalAmount'] = isset($TotalAmount) ? ($TotalAmount) : 0;
                    $InvoiceInsert['TotalAmountRec'] = $TotalAmount;
                    $InvoiceInsert['DiscountSubTotal'] = isset($DiscountSubTotal) ? $DiscountSubTotal : 0;
                    $InvoiceInsert['TaxTypeobj'] = json_encode(['18' => $TotalTaxAmount]);
                    $InvoiceInsert['status'] = 1;
                    $InvoiceInsert['PlanType'] = 4;

                    $InvoiceInsert['created_at'] = date('Y-m-d H:i:s');
                    $InvoiceInsert['updated_at'] = date('Y-m-d H:i:s');

                    $ItemInsert[0]['ItemName'] = $ItemName;
                    $ItemInsert[0]['Quantity'] = 1;
                    $ItemInsert[0]['Rate'] = $SubTotal;
                    $ItemInsert[0]['TaxType'] = 18;
                    $ItemInsert[0]['Amount'] = $SubTotal;
                    $ItemInsert[0]['TaxAmount'] = $TotalTaxAmount;
                    $ItemInsert[0]['TotalDiscount'] = 0;
                    $ItemInsert[0]['discount'] = 0;
                    $ItemInsert[0]['discountedAmount'] = $SubTotal;
                    $ItemInsert[0]['Itemid'] = $Itemid;
                    $ItemInsert[0]['created_at'] = date('Y-m-d H:i:s');
                    $ItemInsert[0]['updated_at'] = date('Y-m-d H:i:s');

                    $invoice_id = Invoices::insertGetId($InvoiceInsert);

                    foreach ($ItemInsert as $key => $rowss) {
                        $Inset = $rowss;
                        $Inset['invoice_id'] = $invoice_id;
                        invoices_items::insertGetId($Inset);
                    }

                    $checkExist = LoyaltyUserCard::where('AgencyID', $user->AgencyID)
                        ->where('user_id', $user->id)->where('card_id', $card_id)->first();
                    if (empty($checkExist)) {
                        LoyaltyUserCard::where('AgencyID', $user->AgencyID)->where('user_id', $user->id)->update(['status' => 'inactive']);
                        $AddCard = LoyaltyUserCard::insertGetId($CreateData);
                    }

                    $updateData['MarketPlaceID'] = (isset($checkMarkup->id) && $checkMarkup->id > 0) ? $checkMarkup->id : 0;
                    $updateData['VIPPaymentStatus'] = 1;
                    $updateData['VIPAgency'] = 1;
                    $updateData['updated_at'] = date('Y-m-d H:i:s');
                    incorporation_details::where('UserSysId', $user->id)->where('AgencyID', $request->user()->AgencyID)->update($updateData);
                    $referral_earning = isset($user->details->referralUser->referral_earning) ? $user->details->referralUser->referral_earning : 0;
                    if (!empty($user->details->referral_user_id) && $user->details->referral_user_id > 0 && $referral_earning > 0) {
                        $walletInsert = [
                            "payer_id" => ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID,
                            "payee_id" => $user->details->referral_user_id,
                            "points" => round($referral_earning, 2),
                            "RewardMode" => "Earn",
                            "description" => "Referral Earning",
                            "currency" => "INR"
                        ];
                        $walletInsert['AgencyID'] = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
                        $walletInsert['UserSysId'] = $request->user()->id;
                        $walletInsert['PlanType'] = 7;
                        $this->rewardService->addPoints($walletInsert);
                    }
                    DB::commit();
                } else {
                    $card_id = $checkUserCardExist->card_id;
                }
            }
            if (empty($request->storeToken) && !empty($request->store_id)) {
                $store = Store::where('AgencyID', $AgencyID)->where('id', $request->store_id)->first();
                // $token = $store->createToken('store-token')->plainTextToken;
                // $request->merge(['storeToken' => $token]);
                $request->merge(['storeToken' => '131|6q2oe0Ok0LHJlu50FTVEeFcUCqu0rzzJeuJMd3nfc9533c6d']);
            }

            $accessToken = PersonalAccessToken::findToken($request->storeToken);
            if (!$accessToken) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 401,
                    ],
                    'message' => 'Unauthorized Store'
                ]);
            }

            $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
            $UserSysId = $request->user()->id;
            $Reward = LoyaltyReward::select('dealtype', 'dealvalue', 'ownervalue', 'custvalue', 'max_reward_value', 'maxdiscountvalue', 'rewardtype', 'ordervalue', 'rewardvalue')->where('AgencyID', $AgencyID)->where('reward_id', $request->reward_id)->first();
            $dealtype = isset($Reward->dealtype) ? (int)$Reward->dealtype : 0;
            $rewardtype = isset($Reward->rewardtype) ? $Reward->rewardtype : 0;
            $ordervalue = isset($Reward->ordervalue) ? $Reward->ordervalue : 0;
            $rewardvalue = isset($Reward->rewardvalue) ? $Reward->rewardvalue : 0;
            $dealvalue = isset($Reward->dealvalue) ? $Reward->dealvalue : 0;
            $ownervalue = isset($Reward->ownervalue) ? $Reward->ownervalue : 0;
            $custvalue = isset($Reward->custvalue) ? $Reward->custvalue : 0;
            $max_reward_value = isset($Reward->max_reward_value) ? $Reward->max_reward_value : 0;
            $maxdiscountvalue = isset($Reward->maxdiscountvalue) ? $Reward->maxdiscountvalue : 0;
            if ($dealtype === 0 && !($request->order_amount < $ordervalue)) {
                $discount = (($request->order_amount * (float)$dealvalue) / 100);
                $discountOwner = (($request->order_amount * (float)$ownervalue) / 100);
                $discountCust = (($request->order_amount * (float)$custvalue) / 100);
            } else if (!($request->order_amount < $ordervalue)) {
                $discount = $dealvalue;
                $discountOwner = $ownervalue;
                $discountCust = $custvalue;
            } else {
                $discount = 0;
                $discountOwner = 0;
                $discountCust = 0;
            }

            if ($rewardtype === 0 && !($request->order_amount < $ordervalue)) {
                $rewardEarns = (($request->order_amount * (float)$rewardvalue) / 100);
            } elseif ($rewardtype === 1 && !($request->order_amount < $ordervalue)) {
                $rewardEarns = $rewardvalue;
            } else {
                $rewardEarns = 0;
            }
            if ($rewardEarns > $max_reward_value) {
                $rewardEarns = $max_reward_value;
            }
            if ($discount > $maxdiscountvalue) {
                $discount = $maxdiscountvalue;
                $discountOwner = (($maxdiscountvalue * (float)$ownervalue) / 100);
                $discountCust = (($maxdiscountvalue * (float)$custvalue) / 100);
            }
            $request->merge(['discount' => $discount]);
            $request->merge(['user_id' => $request->user()->id]);
            $request->merge(['card_id' => $card_id]);
            $accessToken->load(['tokenable' => function ($query) {
                $query->select('id', 'store_name', 'email', 'OTPAllowed', 'swipelimit', 'monthlyswipe', 'FaceRecognition', 'noOfPass', 'vendortype', 'event_date', 'rewardrequired'); // Add store fields
            }]);
            $storeData = $accessToken->tokenable;
            $TotalRewardEarning = RewardEarn::TotalRewardEarning($user, ['user_id' => $request->user_id]);

            $availablereward = isset($TotalRewardEarning->total_rewardearn) ? (float)$TotalRewardEarning->total_rewardearn : 0;
            $rewardrequired = isset($storeData->rewardrequired) ? (float)$storeData->rewardrequired : 0;

            if ($storeData && $storeData->vendortype == 1) {
                $eventDate = Carbon::parse($storeData->event_date);
                $today = Carbon::today();
                if ($eventDate->lt($today)) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 403,
                        ],
                        'message' => "This event has already expired"
                    ]);
                }
                $TotalReddemPass = LoyaltyRedemption::where('AgencyID', $AgencyID)->where('stores_id', $accessToken->tokenable_id)->count();
                $Isredeem = LoyaltyRedemption::where('AgencyID', $AgencyID)->where('user_id', $request->user_id)->where('stores_id', $accessToken->tokenable_id)->count();

                if ($TotalReddemPass >= $storeData->noOfPass) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 403,
                        ],
                        'message' => "No more pass available"
                    ]);
                }
                if ($Isredeem > 0) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 403,
                        ],
                        'message' => "The pass you already swiped for (or downloaded) has been successfully saved. Please check your Pass History to view and use it."
                    ]);
                }

                if ($availablereward < $rewardrequired) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 403,
                        ],
                        'message' => "Insufficient reward balance : to redeem this required at least " . $rewardrequired . " reward points"
                    ]);
                }
            }

            if ($storeData && $storeData->swipelimit == 1) {
                $todayCount = LoyaltyRedemption::where('AgencyID', $AgencyID)->where('user_id', $request->user_id)->where('stores_id', $accessToken->tokenable_id)->whereDate('redemption_date', Carbon::today())->count();
                $yearCount = LoyaltyRedemption::where('AgencyID', $AgencyID)->where('user_id', $request->user_id)->where('stores_id', $accessToken->tokenable_id)->whereYear('redemption_date', Carbon::now()->year)->count();
                $TotalUsed = ($todayCount + $yearCount);
                if ($storeData->vendortype == 1 && $TotalUsed >= $storeData->noOfPass) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 403,
                        ],
                        'message' => "No more pass available"
                    ]);
                }
                if (($todayCount >= $storeData->monthlyswipe) || ($yearCount >= $storeData->monthlyswipe)) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 403,
                        ],
                        'message' => "We have detected that your limit has exceeded the maximum allowed number of swipes/transactions at this store/events"
                    ]);
                }
            }

            if ($storeData && $storeData->OTPAllowed == 1) {
                $otp = !empty($request->otp) ? $request->otp : 0;
                $verified = true; //$this->otpService->verifyOtp($user, $request->phone, $otp);
            } else {
                $verified = true;
            }

            if ($verified) {
                $checkReward = StoresMapping::where('AgencyID', $AgencyID)->where('reward_id', $request->reward_id)
                    ->where('stores_id', $accessToken->tokenable_id)->where('isdelete', 0)->first();
                $stores_id = !empty($accessToken->tokenable_id) ? $accessToken->tokenable_id : 0;

                if ($checkReward && $accessToken && $stores_id > 0) {
                    // Verify the card belongs to the user
                    $cardExists = DB::table('user_card')
                        ->where(function ($query) use ($user) {
                            if ($user->UserType == 1) {
                                $query->where('user_card.AgencyID', $user->id);
                            } else {
                                $query->where('user_card.UserSysId', $user->id);
                            }
                        })->where('user_id', $request->user_id)->where('user_card.status', 'active')->where('card_id', $request->card_id)->exists();
                    if (!$cardExists) {
                        return response()->json([
                            'status' => [
                                'success' => false,
                                'httpStatus' => 403,
                            ],
                            'message' => 'The specified card does not belong to this user'
                        ]);
                    }
                    // pr($request->all());
                    // pr($rewardEarns);
                    // pr($discountCust);
                    // pr($stores_id);
                    // die;

                    try {
                        $limit = 12;
                        $redem_id = 0;
                        for ($i = 0; $i < $limit; $i++) {
                            $redem_id .= mt_rand(0, 9);
                        }
                        $uploadPath = public_path('uploads/redemption/' . $AgencyID . '/' . $stores_id . '/receipt');

                        // ✅ Create folder if not exists
                        if (!File::exists($uploadPath)) {
                            File::makeDirectory($uploadPath, 0755, true);
                        }
                        // ✅ Check & upload file
                        if ($request->hasFile('receipt')) {
                            $file = $request->file('receipt');
                            // Generate unique file name
                            $fileName = 'receipt_' . time() . '_' . Str::random(6) . '.' . $file->getClientOriginalExtension();
                            // Move file
                            $file->move($uploadPath, $fileName);
                            // Save path in DB (relative path recommended)
                            $receipt = url('uploads/redemption/' . $AgencyID . '/' . $stores_id . '/receipt/' . $fileName);
                        } else {
                            $receipt = null; // optional
                        }
                        // Call the stored procedure
                        $results = DB::select(
                            'CALL process_redemption(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @status, @message)',
                            [
                                $request->user_id,
                                $request->card_id,
                                $request->reward_id,
                                $AgencyID,
                                $UserSysId,
                                !empty($request->discount) ? $request->discount : 0,
                                !empty($request->order_amount) ? $request->order_amount : 0,
                                $stores_id,
                                !empty($discountOwner) ? $discountOwner : 0,
                                !empty($discountCust) ? $discountCust : 0,
                                !empty($redem_id) ? 'GTR' . $redem_id : 0,
                                !empty($receipt) ? $receipt : null,
                            ]
                        );

                        // Get the output parameters
                        $results = DB::select('SELECT @status as status, @message as message');
                        $status = $results[0]->status;
                        $message = $results[0]->message;

                        if ($status === 'SUCCESS') {
                            if ($storeData && $storeData->vendortype == 1) {

                                $RewardRedeem = [
                                    "points" => ceil($rewardrequired),
                                    "description" => 'Event Pass - ' . $storeData->store_name,
                                    'AgencyID' => ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID,
                                    'UserSysId' =>  $request->user()->id,
                                    "payer_id" => $request->user_id,
                                    "payee_id" => $AgencyID,
                                    "RewardMode" => "Pay",
                                    'PlanType' => 5,
                                ];
                                $redemption = $this->rewardService->transferPoints($RewardRedeem);
                            }

                            if ($rewardEarns > 0) {
                                $RewardInsert = [
                                    'AgencyID' => ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID,
                                    'UserSysId' =>  $request->user()->id,
                                    "payer_id" => $AgencyID,
                                    "payee_id" => $request->user_id,
                                    "points" => $rewardEarns,
                                    "RewardMode" => "Earn",
                                    'PlanType' => 5,
                                    'description' => 'Earn on Redeem Vendor ID - ' . $stores_id,
                                ];
                                $this->rewardService->addPoints($RewardInsert);
                            }

                            return response()->json([
                                'status' => [
                                    'success' => true,
                                    'httpStatus' => 200,
                                ],
                                'message' => $message,
                                'data' => [
                                    'user_id' => $request->user_id,
                                    'card_id' => $request->card_id,
                                    'reward_id' => $request->reward_id,
                                    'StoreId' => $stores_id
                                ]
                            ], 200);
                        } else {
                            return response()->json([
                                'status' => [
                                    'success' => false,
                                    'httpStatus' => 400,
                                ],
                                'message' => $message
                            ]);
                        }
                    } catch (\Exception $e) {
                        return response()->json([
                            'status' => [
                                'success' => false,
                                'httpStatus' => 500,
                            ],
                            'message' => 'Redemption processing failed',
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    DB::rollback();
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 202,
                        ],
                        'message' => 'This reward is currently unavailable in your store'
                    ]);
                }
            } else {
                DB::rollback();
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 400,
                    ],
                    'message' => 'Invalid OTP or mobile number',
                ]);
            }
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 500,
                ],
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function calculateServiceTax($intAmount, $percentAgencySTax)
    {
        $intAmount = (float) $intAmount;
        $intNetSTax = (($intAmount * (float)$percentAgencySTax) / 100);
        $BasePriceWithSTax = $intNetSTax + $intAmount;
        $arrSerciceTax = array(
            "BasePrice" => $intAmount,
            "serviceTaxAmount" => $intNetSTax,
            "BasePriceWithSTax" => $BasePriceWithSTax,
            "ServiceTaxPercentage" => $percentAgencySTax,
        );
        return $arrSerciceTax;
    }
    public function loyaltyCard(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $cardNumbers = isset($request->cardNumbers) ? $request->cardNumbers : [];
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $post['cardNumbers'] = $cardNumbers;
                $result = LoyaltyCard::getloyaltycard($user, $perPage, $post);
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
    public function loyaltyCardUnAssign(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $cardNumbers = isset($request->cardNumbers) ? $request->cardNumbers : [];
                $keyword = isset($request->keyword) ? $request->keyword : null;
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $post['cardNumbers'] = $cardNumbers;
                $post['keyword'] = $keyword;
                $result = LoyaltyCard::getUnAssignloyaltycardAuto($user, $perPage, $post);
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
    public function loyaltyUserCard(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $Filter = isset($request->Filter) ? $request->Filter : [];
                $cardNumbers = isset($request->cardNumbers) ? $request->cardNumbers : [];
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $post['cardNumbers'] = $cardNumbers;
                $post['Filter'] = $Filter;
                $result = LoyaltyUserCard::getloyaltyUserCard($user, $perPage, $post);
                if ($user && $result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => $result,
                        'UserType' => UserType(),
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
    public function loyaltyReward(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $result = LoyaltyReward::getloyaltyReward($user, $perPage);
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
    public function loyaltyRewardPrent(Request $request, $parent_id)
    {
        try {
            if ($request->isMethod('post')) {
                $user = $request->user();
                $result = LoyaltyReward::getloyaltyRewardParent($user, $parent_id);
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
    public function storeReward(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $stores_id = (isset($request->stores_id) && $request->stores_id > 0) ? $request->stores_id : 0;
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $post['stores_id'] = $stores_id;
                $result = LoyaltyReward::getStoreReward($user, $perPage, $post);
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

    public function getRedemptionHistory(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $validator = Validator::make($request->all(), [
                    'storeToken' => 'required',
                ], [
                    'storeToken' => "Token can't be null.",
                ]);
                if ($validator->fails()) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 422,
                        ],
                        'message' => 'Validation failed',
                        'error' => $validator->errors()
                    ]);
                }
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $accessToken = PersonalAccessToken::findToken($request->storeToken);
                if (!$accessToken) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 401,
                        ],
                        'message' => 'Unauthorized Store'
                    ]);
                }
                $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                $post['stores_id'] = $accessToken->tokenable_id;
                $post['AgencyID'] = $AgencyID;

                $result = LoyaltyRedemption::getloyaltyRedemtion($user, $perPage, $post);

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

    public function getRedemptionHistoryCustomer(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                // pr($user);
                // die;
                $post['stores_id'] = 0;
                $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                $post['AgencyID'] = $AgencyID;
                $result = LoyaltyRedemption::getloyaltyRedemtionCustomer($user, $perPage, $post);
                $total = RewardEarn::where('AgencyID', $AgencyID)->where('user_id', $user->id)->sum('rewardearn');
                if ($user && $result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'reward_earn' => round($total, 2),
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
    public function getRedemptionHistoryAll(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $post['stores_id'] = 0;
                $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                $post['AgencyID'] = $AgencyID;
                $result = LoyaltyRedemption::getloyaltyRedemtion($user, $perPage, $post);
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
    public function getLoyaltyProgram(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $user = $request->user();
                $result = LoyaltyProgram::where('is_active', 1)->get();
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
    public function addcard(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $user = $request->user();
                $validator = Validator::make($request->all(), [
                    'program_id' => 'required',
                    'status' => 'required',
                    'card_type' => 'required|max:191',
                    'card_number' => 'required',
                    'issue_date' => 'required',
                    'expiration_date' => 'required'
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
                    //print_r($errorArray);
                    return response()->json([
                        'status' => false,
                        'httpStatus' => 201,
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                } else {
                    $CreateData = [
                        'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                        'UserSysId' => $request->user()->id,
                        'program_id' => $request->program_id,
                        'status' => $request->status,
                        'card_number' => $request->card_number,
                        'card_type' => $request->card_type,
                        'issue_date' => $request->issue_date,
                        'initial_points' => $request->initial_points,
                        'expiration_date' => $request->expiration_date
                    ];
                    $card_id = (isset($request->card_id) && $request->card_id > 0) ? $request->card_id : 0;
                    if ($card_id > 0) {
                        LoyaltyCard::where('card_id', $card_id)->update($CreateData);
                        $AddCard = $card_id;
                        $message = 'Edited card successfully!';
                    } else {
                        $AddCard = LoyaltyCard::insertGetId($CreateData);
                        $message = 'Add card successfully!';
                    }

                    if ($user && $AddCard) {
                        return response()->json([
                            'status' => [
                                'success' => true,
                                'httpStatus' => 200,
                            ],
                            'message' => $message,
                            'data' => $AddCard,
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
    public function addreward(Request $request)
    {
        DB::beginTransaction();
        try {
            if ($request->isMethod('post')) {

                // return response()->json([
                //     'status' => [
                //         'success' => false,
                //         'httpStatus' => 500,
                //     ],
                //     'data' => $request->all(),
                //     'program_id' => explode(',', $request->program_id),
                //     'message' => 'Oops something went wrong',
                // ]);
                $user = $request->user();
                // pr($request->file('reward_logo'));
                // die('ddd');
                $validator = Validator::make($request->all(), [
                    'program_id' => 'required',
                    'stores_id' => 'required',
                    'is_active' => 'required',
                    'reward_name' => 'required|max:191',
                    'description' => 'required',
                    'maxdiscountvalue' => 'required'
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
                    //print_r($errorArray);
                    return response()->json([
                        'status' => false,
                        'httpStatus' => 201,
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                } else {
                    $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
                    $reward_id = (isset($request->reward_id) && $request->reward_id > 0) ? $request->reward_id : 0;
                    $parent_id = (isset($request->parent_id) && $request->parent_id > 0) ? $request->parent_id : 0;
                    if ($request->program_id && explode(',', $request->program_id)) {
                        $parentId = 0;
                        foreach (explode(',', $request->program_id) as $key => $value) {
                            $exists = LoyaltyReward::where('parent_id', $parent_id)->where('reward_id', $reward_id)->where('program_id', $value)->where('AgencyID', $AgencyID)->exists();

                            $CreateData = [
                                'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                                'UserSysId' => $request->user()->id,
                                'program_id' => $value, //$request->program_id,
                                // 'stores_id' => $request->stores_id,
                                'is_active' => $request->is_active,
                                'points_required' => $request->points_required,
                                'reward_name' => $request->reward_name,
                                // 'stock_quantity' => 0,
                                'description' => $request->description,
                                'start_date' => (isset($request->start_date) && !empty($request->start_date) && $request->start_date !== "null") ? $request->start_date : null,
                                'end_date' => (isset($request->end_date) && !empty($request->end_date) && $request->end_date !== "null") ? $request->end_date . ' 23:59:00' : null,
                                'dealtype'         => isset($request->dealtype) ? (int)$request->dealtype : 0,
                                'rewardtype'         => isset($request->rewardtype) ? (int)$request->rewardtype : 0,
                                'dealvalue'         => isset($request->dealvalue) ? (float)$request->dealvalue : 0,
                                'ownervalue'         => isset($request->ownervalue) ? (float)$request->ownervalue : 0,
                                'custvalue'         => isset($request->custvalue) ? (float)$request->custvalue : 0,
                                'ordervalue'         => isset($request->ordervalue) ? (float)$request->ordervalue : 0,
                                'rewardvalue'         => isset($request->rewardvalue) ? (float)$request->rewardvalue : 0,
                                'maxdiscountvalue'    => isset($request->maxdiscountvalue) ? (float)$request->maxdiscountvalue : 0,
                                'max_reward_value'    => isset($request->max_reward_value) ? (float)$request->max_reward_value : 0,
                            ];
                            $imagePath = '';
                            if ($request->hasFile('reward_logo')) {
                                $image = $request->file('reward_logo');
                                $originalName = $request->file('reward_logo')->getClientOriginalName();
                                $originalName = cleanImageName($originalName);
                                $imageName = $originalName . '_' . time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                                // $imageName = $originalName; //. '.' . $image->getClientOriginalExtension();
                                $image->move(public_path('uploads/reward'), $imageName);
                                $imagePath = 'uploads/reward/' . $imageName;
                            }
                            if (!empty($imagePath)) {
                                $CreateData['reward_logo'] = $this->APP_URL . '/' . $imagePath;
                            }


                            if ($reward_id > 0 && $exists) {
                                $CreateData['updated_at'] = date('Y-m-d H:i:s');
                                LoyaltyReward::where('reward_id', $reward_id)->update($CreateData);
                                $insertGetId = $reward_id;
                                $message = 'Edited reward successfully!';
                            } else {
                                if ($parent_id > 0) {
                                    $exists = LoyaltyReward::where('parent_id', $parent_id)->where('reward_id', $reward_id)->where('AgencyID', $AgencyID)->exists();
                                }
                                if ($exists) {
                                    $CreateData['updated_at'] = date('Y-m-d H:i:s');
                                    LoyaltyReward::where('reward_id', $reward_id)->update($CreateData);
                                    $insertGetId = $reward_id;
                                    $message = 'Edited reward successfully!';
                                } else {
                                    $CreateData['created_at'] = date('Y-m-d H:i:s');
                                    $CreateData['updated_at'] = date('Y-m-d H:i:s');
                                    if ($reward_id > 0) {
                                        $CreateData['parent_id'] = ($parent_id > 0) ? $parent_id : $reward_id;
                                    } else {
                                        $CreateData['parent_id'] = $parentId;
                                    }
                                    $insertGetId = LoyaltyReward::insertGetId($CreateData);
                                    if ($reward_id > 0) {
                                        $parentId = ($parent_id > 0) ? $parent_id : $reward_id;
                                    } else {
                                        if ($key == 0) {
                                            $parentId = $insertGetId;
                                        }
                                    }
                                    $message = 'Add reward successfully!';
                                }
                            }

                            if ($user && $insertGetId) {
                                if ($request->stores_id) {

                                    $MappingUpdate['isdelete'] = 1;
                                    $MappingUpdate['updated_at'] = date('Y-m-d H:i:s');
                                    StoresMapping::where('reward_id', $insertGetId)->where('AgencyID', $AgencyID)->update($MappingUpdate);
                                    $stores_ids = json_decode($request->stores_id, 1);
                                    foreach ($stores_ids as $vl) {
                                        $Mapping = [
                                            'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                                            'UserSysId' => $request->user()->id,
                                            'stores_id' => $vl['id'],
                                            'store_name' => $vl['store_name'],
                                            'reward_id' => $insertGetId,
                                        ];

                                        $Mapping['created_at'] = date('Y-m-d H:i:s');
                                        $Mapping['updated_at'] = date('Y-m-d H:i:s');
                                        StoresMapping::insertGetId($Mapping);
                                    }
                                }
                                DB::commit();
                            } else {
                                DB::rollback();
                                return response()->json([
                                    'status' => [
                                        'success' => false,
                                        'httpStatus' => 500,
                                    ],
                                    'message' => 'Oops something went wrong',
                                ]);
                            }
                        }

                        return response()->json([
                            'status' => [
                                'success' => true,
                                'httpStatus' => 200,
                            ],
                            'message' => $message,
                            'data' => $insertGetId,
                        ]);
                    }
                }
            }
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }
    public function assigncard(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $user = $request->user();
                $validator = Validator::make($request->all(), [
                    'user_id' => 'required',
                    'card_id' => 'required',
                    'status' => 'required|max:191',
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
                    //print_r($errorArray);
                    return response()->json([
                        'status' => false,
                        'httpStatus' => 201,
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                } else {
                    $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
                    $cardDetails = LoyaltyCard::getcardDetails($user, $request->card_id);
                    $card_number = LoyaltyUserCard::createUniqueCardNumber($user);
                    $MasterCardNo = (str_replace(' ', '', ($cardDetails->card_number)));

                    $CreateData = [
                        'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                        'UserSysId' => $request->user()->id,
                        'user_id' => $request->user_id,
                        'card_id' => $request->card_id,
                        'points_balance' => (isset($request->points_balance) && !empty($request->points_balance)) ? $request->points_balance : 0,
                        'status' => $request->status,
                        'card_number' => $MasterCardNo,
                        // 'card_number' => $MasterCardNo . $card_number['card_no'],
                        'card_no' => $card_number['card_no'],
                        'is_primary_card' => $request->is_primary_card,
                        'notes' => !empty($request->notes) ? $request->notes : '',
                        'activation_date' => (isset($request->activation_date) && !empty($request->activation_date) && $request->activation_date !== "null") ? $request->activation_date : null,
                        'deactivation_date' => (isset($request->deactivation_date) && !empty($request->deactivation_date) && $request->deactivation_date !== "null") ? $request->deactivation_date . ' 23:59:00' : null
                    ];

                    $checkExist = LoyaltyUserCard::where('AgencyID', $AgencyID)->where('user_id', $request->user_id)->where('card_id', $request->card_id)->first();

                    if ($checkExist && $request->is_edit == 1) {
                        unset($CreateData['card_number']);
                        unset($CreateData['card_no']);
                        $AddCard = LoyaltyUserCard::where('AgencyID', $AgencyID)->where('user_id', $request->user_id)->where('card_id', $request->card_id)->update($CreateData);
                        $message = 'Update successfully!';
                    } elseif (empty($checkExist) && $request->is_edit == 0) {
                        $checkExistUser = LoyaltyUserCard::where('AgencyID', $AgencyID)->where('user_id', $request->user_id)->first();

                        $checkExistCard = LoyaltyUserCard::where('AgencyID', $AgencyID)->where('card_id', $request->card_id)->first();
                        if ($checkExistCard) {
                            return response()->json([
                                'status' => [
                                    'success' => false,
                                    'httpStatus' => 501,
                                ],
                                'message' => 'The selected card is already assigned to other customer.!',
                            ]);
                        }

                        if ($checkExistUser) {
                            // pr($CreateData);
                            // pr($checkExistUser);

                            // die;
                            $AddCard = LoyaltyUserCard::where('AgencyID', $AgencyID)->where('user_id', $request->user_id)->update($CreateData);
                            $message = 'Update successfully!';
                        } else {
                            $AddCard = LoyaltyUserCard::insertGetId($CreateData);
                            $message = 'Assign successfully!';
                        }
                    } else {
                        $checkExistCard = LoyaltyUserCard::where('card_id', $request->card_id)->first();
                        if ($checkExistCard) {
                            return response()->json([
                                'status' => [
                                    'success' => false,
                                    'httpStatus' => 501,
                                ],
                                'message' => 'The selected card is already assigned to other customer.!',
                            ]);
                        }
                        if ($checkExist) {
                            return response()->json([
                                'status' => [
                                    'success' => false,
                                    'httpStatus' => 501,
                                ],
                                'message' => 'The selected card is already assigned to the chosen customer.!',
                            ]);
                        } else {
                            $AddCard = LoyaltyUserCard::insertGetId($CreateData);
                            $message = 'Assign successfully!';
                        }
                    }

                    if ($user) {
                        return response()->json([
                            'status' => [
                                'success' => true,
                                'httpStatus' => 200,
                            ],
                            'message' => $message,
                            'data' => $AddCard,
                        ]);
                    } else {
                        return response()->json([
                            'status' => [
                                'success' => false,
                                'httpStatus' => 501,
                            ],
                            'message' => 'Oops something went wrong',
                        ]);
                    }
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
    public function validateusercard(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $user = $request->user();
                $validator = Validator::make($request->all(), [
                    'store_id' => 'required',
                    'card_number' => 'required',
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
                    //print_r($errorArray);
                    return response()->json([
                        'status' => false,
                        'httpStatus' => 201,
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                } else {

                    $cardDetails = LoyaltyUserCard::getloyaltyUserCardDetails($user, $request->all());
                    // pr($cardDetails);
                    // die;
                    if ($user && $cardDetails) {
                        return response()->json([
                            'status' => [
                                'success' => true,
                                'httpStatus' => 200,
                            ],
                            'message' => 'Success',
                            'data' => $cardDetails,
                        ]);
                    } else {
                        return response()->json([
                            'status' => [
                                'success' => false,
                                'httpStatus' => 500,
                            ],
                            'message' => 'This loyalty card is either inactive or has not been properly configured with an associated reward. Kindly verify the reward status in the reward list',
                        ]);
                    }
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

    public function getRedemptionReport(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $post['stores_id'] = 0;
                $user = $request->user();
                $result = LoyaltyRedemption::getloyaltyRedemtionReport($user, $perPage, $post);
                // pr($result);
                // die('d');
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
    public function getRedemptionReportCustomer(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $result = LoyaltyRedemption::getlRedemtionReportCustomer($user, $perPage);
                // pr($result);
                // die('d');
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
    public function getRedemptionReportReward(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $result = LoyaltyRedemption::getlRedemtionReportReward($user, $perPage);
                // pr($result);
                // die('d');
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
    public function getRedemptionReportApp(Request $request)
    {
        try {

            if ($request->isMethod('post')) {
                $validator = Validator::make($request->all(), [
                    'storeToken' => 'required',
                ], [
                    'storeToken' => "Token can't be null.",
                ]);
                if ($validator->fails()) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 422,
                        ],
                        'message' => 'Validation failed',
                        'error' => $validator->errors()
                    ]);
                }
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $accessToken = PersonalAccessToken::findToken($request->storeToken);
                if (!$accessToken) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 401,
                        ],
                        'message' => 'Unauthorized Store'
                    ]);
                }
                $post['stores_id'] = $accessToken->tokenable_id;
                $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                $post['AgencyID'] = $AgencyID;

                $result = LoyaltyRedemption::getloyaltyRedemtionReport($user, $perPage, $post);

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


    public function customerReward(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();

                $result = LoyaltyReward::getCustomerReward($user, $perPage);
                //  pr($result);
                //  pr($user);
                // die;
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
                        'message' => 'No record found',
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
    public function printPass(Request $request, $redem_id)
    {
        $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
        $UserSysId = $request->user()->id;
        $DownloadData = LoyaltyRedemption::DownloadEventPass($request->user(), $redem_id);
        $action = isset($request->action) ? $request->action : 1;
        $data = $DownloadData;
        ini_set('memory_limit', '1G');
        $html = view('Event.printPass', $data)->render();
        // echo ($html);
        // pr($DownloadData);
        // die;
        $mpdf = new Mpdf([
            'tempDir' => __DIR__ . '/tmp',
            'mode' => 'utf-8',
            'displayDefaultOrientation' => false,
            'format' => 'A4',
        ]);
        $mpdf->AddPageByArray([
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_left' => 0,
            'margin_right' => 0,
        ]);
        $mpdf->defaultfooterline = false;
        // $mpdf->setFooter('{PAGENO}');
        // $mpdf->SetWatermarkText('CONFIRMED');
        $mpdf->showWatermarkText = false;
        // $mpdf->setFooter('{PAGENO} / {nb}');
        $mpdf->WriteHTML($html);

        $filename = $redem_id . '.pdf';

        $header = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"'
        ];
        $destinationPath = public_path('storage/upload/hotel/');

        // Ensure the folder exists
        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0777, true);
        }
        file_put_contents($destinationPath . $filename, $mpdf->Output($filename, "S"));
        //Storage::disk('public')->put($destinationPath  . $filename, $mpdf->Output($filename, "S"));
        // Get file back from storage with the give header informations
        // $path = public_path('storage/' . $filename);
        $path = $destinationPath . $filename;

        if ($action == 1) {
            return response()->make($mpdf->Output($filename, 'S'), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"'
            ]);
        } else {
            return response()->download($path, '', $header);
        }
        // pr($DownloadData);
        // die;
        pr($redemption_id);
        die;
    }

    public function cityService(Request $request)
    {
        try {
            $city = trim($request->city ?? '');

            // `city` present -> auto-detect's exact serviceability check.
            // `city` empty   -> store-backed city search / top-N popular list.
            return $city !== ''
                ? $this->resolveCityServiceability($request)
                : $this->searchServiceableCities($request);
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

    private function resolveCityServiceability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'city' => 'required|string|max:191',
            'state' => 'nullable|string|max:191',
            'country' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 201,
                ],
                'message' => $validator->errors()->first(),
            ]);
        }

        $city = trim($request->city);
        $country = trim($request->country ?? '');
        $user = $request->user();

        $query = Store::select(
            'stores.id',
            'stores.store_name',
            'stores.city',
            'static_cities.id as matched_city_id',
            'static_cities.cityName',
            'static_cities.fullRegionName'
        )
            ->join('static_cities', 'stores.city', '=', 'static_cities.id')
            ->whereRaw('LOWER(static_cities.cityName) = ?', [strtolower($city)]);

        if ($country !== '') {
            $query->whereRaw('LOWER(static_cities.countryName) = ?', [strtolower($country)]);
        }

        /*
    |--------------------------------------------------------------------------
    | Agency / User filtering
    |--------------------------------------------------------------------------
    */

        if ($user->UserType == 1) {

            $query->where(
                'stores.AgencyID',
                $user->id
            );
        } else {

            $query->where(
                'stores.UserSysId',
                $user->id
            );
        }

        $matchedRow = $query->first();

        $isServiceable = (bool) $matchedRow;
        $matchedStore = null;
        $matchedCity = null;

        if ($matchedRow) {
            $matchedStore = [
                'id' => $matchedRow->id,
                'store_name' => $matchedRow->store_name,
                'city' => $matchedRow->city,
            ];
            $matchedCity = [
                'id' => $matchedRow->matched_city_id,
                'cityName' => $matchedRow->cityName,
                'fullRegionName' => $matchedRow->fullRegionName,
            ];
        }

        return response()->json([
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'message' => $isServiceable
                ? 'We currently provide service in ' . $city . '.'
                : 'Currently we are not providing service at your location, please wait for us!',
            'data' => [
                'serviceable' => $isServiceable,
                'city' => $city,
                'store' => $matchedStore,
                'matchedCity' => $matchedCity,
                'cities' => [],
            ],
        ]);
    }

    private function searchServiceableCities(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'keyword' => 'nullable|string|max:191',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 422,
                ],
                'message' => $validator->errors()->first(),
            ]);
        }

        $keyword = trim($request->keyword ?? '');
        $limit = (int) ($request->limit ?? 5);
        $user = $request->user();

        $query = Store::select(
            'static_cities.id',
            'static_cities.cityName',
            'static_cities.fullRegionName',
            'stores.id as store_id',
            'stores.store_name',
            'stores.address'
        )
            ->join(
                'static_cities',
                'stores.city',
                '=',
                'static_cities.id'
            );

        /*
    |--------------------------------------------------------------------------
    | Agency / User filtering
    |--------------------------------------------------------------------------
    */

        if ($user->UserType == 1) {

            $query->where(
                'stores.AgencyID',
                $user->id
            );
        } else {

            $query->where(
                'stores.UserSysId',
                $user->id
            );
        }

        if ($keyword !== '') {
            $like = '%' . strtolower($keyword) . '%';

            $query->selectRaw(
                "CASE
                WHEN LOWER(static_cities.cityName) LIKE ? THEN 'city'
                WHEN LOWER(static_cities.fullRegionName) LIKE ? THEN 'region'
                WHEN LOWER(stores.store_name) LIKE ? THEN 'store_name'
                WHEN LOWER(stores.address) LIKE ? THEN 'address'
                ELSE NULL
            END as matched_on",
                [$like, $like, $like, $like]
            );

            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(static_cities.cityName) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(static_cities.fullRegionName) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(stores.store_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(stores.address) LIKE ?', [$like]);
            });
        }

        $rows = $query
            ->orderBy('static_cities.cityName')
            ->limit($limit * 5)
            ->get();

        $priority = ['city' => 0, 'region' => 1, 'store_name' => 2, 'address' => 3];

        $cities = $rows
            ->groupBy('id')
            ->map(function ($group) use ($priority, $keyword) {
                $best = $keyword !== ''
                    ? $group->sortBy(fn($row) => $priority[$row->matched_on] ?? 99)->first()
                    : $group->first();

                return [
                    'id' => $best->id,
                    'cityName' => $best->cityName,
                    'fullRegionName' => $best->fullRegionName,
                    'matchedOn' => $best->matched_on ?? null,
                    'matchedStore' => in_array($best->matched_on ?? null, ['store_name', 'address'], true)
                        ? [
                            'id' => $best->store_id,
                            'store_name' => $best->store_name,
                            'address' => $best->address,
                        ]
                        : null,
                ];
            })
            ->values()
            ->take($limit);

        return response()->json([
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'message' => 'Serviceable cities fetched successfully.',
            'data' => [
                'serviceable' => null,
                'cities' => $cities,
            ],
        ]);
    }

    //To fetch Rewards ,vouchers and store details per store id
    public function storeRewardVouchers(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $stores_id = (isset($request->stores_id) && $request->stores_id > 0) ? $request->stores_id : 0;

                $rewardPerPage = (isset($request->reward_per_page) && $request->reward_per_page > 0) ? $request->reward_per_page : 25;
                $rewardPage = (isset($request->reward_page) && $request->reward_page > 0) ? $request->reward_page : 1;

                $voucherPerPage = (isset($request->voucher_per_page) && $request->voucher_per_page > 0) ? $request->voucher_per_page : 25;
                $voucherPage = (isset($request->voucher_page) && $request->voucher_page > 0) ? $request->voucher_page : 1;

                $user = $request->user();

                if (!$stores_id) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 422,
                        ],
                        'message' => 'stores_id is required',
                    ]);
                }

                $post['stores_id'] = $stores_id;

                $store = Store::select('stores.*', 'static_cities.cityName')
                    ->join('static_cities', 'stores.city', '=', 'static_cities.id')
                    ->with('images')
                    ->where('stores.id', $stores_id)
                    ->first();

                $rewards = LoyaltyReward::getStoreRewardForStoreDetail($user, $rewardPerPage, $rewardPage, $post);
                $vouchers = Vouchers::getActiveVouchersByStore($stores_id, $voucherPerPage, $voucherPage, $user);

                if ($user && $store) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => [
                            'store' => $store,
                            'rewards' => $rewards,
                            'vouchers' => $vouchers,
                        ],
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 404,
                        ],
                        'message' => 'Store not found',
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
