<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\incorporation_details;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Helpers\Helper;
use App\Models\PasswordResetTokens;
use Illuminate\Support\Facades\Auth;
use App\Services\OtpService;
use App\Services\TwilioService;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\DB;
use App\Models\LoyaltyUserCard;
use App\Models\Store;
use App\Models\userDevices;
use App\Models\WalletModel;
use App\Models\WebsiteSetting;
use App\Services\AgentBnplService;
use App\Services\EmailService;

class AuthController extends Controller
{

    public $APP_URL;
    public $APP_NAME;
    protected $emailService;
    protected $twilio;
    protected $otpService;
    protected $bnplService;
    private $SECURITYKEY;
    public function __construct(
        OtpService $otpService,
        AgentBnplService $bnplService,
        TwilioService $twilio,
        EmailService $emailService,
    ) {
        $this->SECURITYKEY = env('SECURITYKEY');
        $this->APP_NAME = env('APP_NAME');
        $this->APP_URL = env('APP_URL');
        $this->otpService = $otpService;
        $this->twilio = $twilio;
        $this->emailService = $emailService;
        $this->bnplService = $bnplService;
    }

    public function register(Request $request)
    {
        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        // $validator = Validator::make($request->all(), [
        //     'agencyName' => 'required|string|max:255',
        //     'fname' => 'required|string|max:255',
        //     'lname' => 'required|string|max:255',
        //     'UserType' => 'required',
        //     'countrycode'  => ['required', 'regex:/^\d{1,4}$/'],
        //     'mobile' => 'required|digits_between:9,12| unique:' . User::class,
        //     'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
        //     'password' => ['required', 'confirmed', Rules\Password::defaults()],
        //     'referral_code' => [
        //         'nullable',
        //         'string',
        //         'size:8',
        //         Rule::exists(incorporation_details::class, 'referral_code')->where(function ($query) use ($AgencyID) {
        //             $query->where('AgencyID', $AgencyID);
        //         })
        //     ],
        // ]);

        if ($request->boolean('isgooglelogin')) {
            // --- GOOGLE LOGIN VALIDATION ONLY ---
            $rules = [
                'fname'     => 'required|string|max:255',
                'lname'     => 'required|string|max:255',
                'email'     => 'required|string|lowercase|email|max:255', // no unique rule
                'UserType'  => 'required',
            ];
        } else {
            // --- NORMAL REGISTRATION VALIDATION ---
            $rules = [
                'agencyName'    => 'required|string|max:255',
                'fname'         => 'required|string|max:255',
                'lname'         => 'required|string|max:255',
                'UserType'      => 'required',
                'countrycode'   => ['required', 'regex:/^\d{1,4}$/'],
                // 'mobile'        => 'required|digits_between:9,12|unique:' . User::class,
                'mobile' => [
                    'required',
                    'digits_between:9,12',
                    Rule::unique('users', 'mobile')->where(function ($query) use ($AgencyID) {
                        return $query->where('AgencyID', $AgencyID);
                    }),
                ],
                'email'         => 'required|string|lowercase|email|max:255|unique:' . User::class,
                'password'      => ['required', 'confirmed', Rules\Password::defaults()],
                'referral_code' => [
                    'nullable',
                    'string',
                    'size:8',
                    Rule::exists(incorporation_details::class, 'referral_code')
                        ->where(fn($q) => $q->where('AgencyID', $AgencyID))
                ],
            ];
        }
        $validator = Validator::make($request->all(), $rules);
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
                'status' => [
                    'success' => false,
                    'httpStatus' => 201,
                ],
                'message' => implode(',', $errorArray),
                'error' => $validator->messages(),
            ]);
        } else {

            try {
                $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
                $is_mobile = (int)$request->is_mobile ?? 0;
                $leadsource = (int)$request->leadsource ?? 12;
                $websiteSettings = WebsiteSetting::where('AgencyID', $AgencyID)->first();
                $data = [
                    'websiteSettings' => $websiteSettings,
                    'user' => $request->user(),
                    'post' => $request->all(),
                ];
                $body = view('emails.register', $data)->render();
                //echo $body;
                //die;
                $subject = 'Welcome to ' . $request->user()->name ?? '';
                $ipAddress = $request->ip();
                if ($request->boolean('isgooglelogin')) {
                    // Check if email already exists
                    $user = User::where('AgencyID', $AgencyID)->where('email', $request->email)->whereIn('UserType', [0, 2])->first();
                    if ($user) {
                        $CardDetails = LoyaltyUserCard::getUserCardDetails($user);
                        $ScanPayActive = userDevices::where('AgencyID', $user->AgencyID)->where('user_id', $user->id)->where('is_verified', 1)->exists();
                        $StoreExist = Store::where('AgencyID', $user->AgencyID)->where('UserSysId', $user->id)->exists();
                        $token = $user->createToken('auth_token')->plainTextToken;
                        $BalanceWallet = WalletModel::WalletBalance($request->user(), $user->id);
                        $BNPLsums = $this->bnplService->sumOfCreditLimit($request->user(), $user->id);
                        $bookable_balance = isset($BalanceWallet['balances']['bookable_balance']) ? $BalanceWallet['balances']['bookable_balance'] : 0;
                        $UserData = [
                            'id' => $user->id,
                            'name' => $user->name,
                            'username' => $user->email,
                            'accountBalance' => $bookable_balance, //$user->WalletBalance,
                            'WalletStatus' => $user->WalletStatus,
                            'CreditLimitStatus' => $user->CreditLimitStatus,
                            'BNPLCreditStatus' => $user->BNPLCreditStatus,
                            'email' => $user->email,
                            'mobile' => $user->mobile,
                            'address' => $user->address,
                            'BNPL' => $BNPLsums,
                            'ScanPayActive' => ($ScanPayActive) ? true : false,
                            'IsStore' => ($StoreExist) ? true : false,
                            'user' => $user,
                        ];
                        return response()->json([
                            'status' => true,
                            'httpStatus' => 200,
                            'token' => $token,
                            // 'expires_at' => $carbon_date->format('Y-m-d H:i:s'),
                            'UserData' => $UserData,
                            'CardDetails' => $CardDetails,
                            'message' => 'Logged In Successfully',
                        ]);
                    }

                    // ❌ Email not found → create fresh account
                    $referralCode = Str::upper(Str::random(8));
                    $referral_earning = 0;
                    $insertGetId = User::insertGetId([
                        'AgencyID' => $AgencyID,
                        'UserSysId' => $request->user()->id,
                        'title' => !empty($request->title) ? $request->title : '',
                        'name' => (isset($request->agencyName) && !empty($request->agencyName)) ? $request->agencyName : $request->fname . ' ' . $request->lname,
                        'fname' => $request->fname,
                        'lname' => $request->lname,
                        'mobile' => isset($request->mobile) ? $request->mobile : 0,
                        'countrycode' => isset($request->countrycode) ? $request->countrycode : 91,
                        'UserType' => (int)$request->UserType,
                        'email' => $request->email,
                        'active' => 1,
                        'WalletStatus' => 1,
                        'is_mobile' => $is_mobile,
                        'password' => Hash::make($referralCode),
                        'created_at' =>  date('Y-m-d H:i:s'),
                        'updated_at' =>  date('Y-m-d H:i:s'),
                    ]);
                    $user = User::find($insertGetId);
                    $InsertIncorpo = array(
                        'UserSysId' => $user->id,
                        'AgencyID' => $user->AgencyID,
                        'mobile' => isset($request->mobile) ? $request->mobile : 0,
                        'agencyName' => (isset($request->agencyName) && !empty($request->agencyName)) ? $request->agencyName : $request->fname . ' ' . $request->lname,
                        'email' => $request->email,
                        'VIPAgency' => isset($request->VIPAgency) ? $request->VIPAgency : 0,
                        'referral_user_id' => NULL,
                        'referral_code' =>  $referralCode,
                        'referral_earning' =>  $referral_earning,
                        'leadsource' => 15,
                        'onboarding_process' => 100,
                        // 'IsPanVerify' => ($AgencyID == 97) ? 0 : 1,
                        'tax_number' => '',
                        'logo' => '',
                        'address' => '',
                        'address1' => '',
                        'country' => '',
                        'city' => '',
                        'pincode' => '',
                        'addressproof' => '',
                        'created_at' =>  date('Y-m-d H:i:s'),
                        'updated_at' =>  date('Y-m-d H:i:s'),
                    );
                    incorporation_details::insertGetId($InsertIncorpo);

                    $token = $user->createToken('auth_token')->plainTextToken;
                    DB::commit();

                    $UserData = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->email,
                        'accountBalance' => $user->WalletBalance,
                        'WalletStatus' => $user->WalletStatus,
                        'email' => $user->email,
                        'mobile' => $user->mobile,
                        'countrycode' => $user->countrycode,
                        'address' => $user->address,
                        'user' => User::find($insertGetId),
                    ];
                    $this->emailService->sendEmail(
                        $request->user(),
                        $user->email,
                        $subject,
                        $body,
                        $ipAddress
                    );
                    return response()->json([
                        'status' => true,
                        'httpStatus' => 200,
                        'token' => $token,
                        'UserData' => $UserData,
                        'message' => 'Registration Successfully',
                    ]);
                }

                $referralCode = Str::upper(Str::random(8));
                $referral_code = isset($request->referral_code) ? $request->referral_code : '';
                $referral_user_id = !empty($referral_code) ? incorporation_details::where('AgencyID', $AgencyID)->where('referral_code', $referral_code)->value('UserSysId') : null;
                if ($AgencyID == 97) {
                    $referral_earning = 50;
                } else {
                    $referral_earning = 0;
                }
                $insertGetId = User::insertGetId([
                    'AgencyID' => $AgencyID,
                    'UserSysId' => $request->user()->id,
                    'title' => !empty($request->title) ? $request->title : '',
                    'name' => $request->agencyName,
                    'fname' => $request->fname,
                    'lname' => $request->lname,
                    'mobile' => $request->mobile,
                    'countrycode' => $request->countrycode,
                    'UserType' => (int)$request->UserType,
                    'email' => $request->email,
                    'active' => 1,
                    'WalletStatus' => 1,
                    'is_mobile' => $is_mobile,
                    'password' => Hash::make($request->password),
                    'created_at' =>  date('Y-m-d H:i:s'),
                    'updated_at' =>  date('Y-m-d H:i:s'),
                ]);
                $user = User::find($insertGetId);
                $InsertIncorpo = array(
                    'UserSysId' => $user->id,
                    'AgencyID' => $user->AgencyID,
                    'mobile' => $request->mobile,
                    'agencyName' => $request->agencyName,
                    'email' => $request->email,
                    'VIPAgency' => isset($request->VIPAgency) ? $request->VIPAgency : 0,
                    'referral_user_id' => ($referral_user_id > 0) ? $referral_user_id : NULL,
                    'referral_code' =>  $referralCode,
                    'referral_earning' =>  $referral_earning,
                    'leadsource' => $leadsource,
                    'onboarding_process' => 10,
                    // 'IsPanVerify' => ($AgencyID == 97) ? 0 : 1,
                    'tax_number' => '',
                    'logo' => '',
                    'address' => '',
                    'address1' => '',
                    'country' => '',
                    'city' => '',
                    'pincode' => '',
                    'addressproof' => '',
                    'created_at' =>  date('Y-m-d H:i:s'),
                    'updated_at' =>  date('Y-m-d H:i:s'),
                );
                incorporation_details::insertGetId($InsertIncorpo);
                $token = $user->createToken('auth_token')->plainTextToken;
                DB::commit();

                $UserData = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->email,
                    'accountBalance' => $user->WalletBalance,
                    'WalletStatus' => $user->WalletStatus,
                    'email' => $user->email,
                    'mobile' => $user->mobile,
                    'countrycode' => $user->countrycode,
                    'address' => $user->address,
                    'user' => User::find($insertGetId),
                ];

                $signature = substr($request->user()->name, 0, 8);
                $ipAddress = $request->ip();
                $OTPS = random_int(100000, 999999);
                $phoneNumber = $request->countrycode . $request->mobile;
                // $otpRequest = $this->otpService->generateOtp(
                //     $request->user(),
                //     $phoneNumber,
                //     'user validation',
                //     $ipAddress,
                //     $OTPS
                // );
                // $to = $phoneNumber; // recipient number
                // $message = "Your OTP for user validation is " . $OTPS . ".  \nValid for 5 minutes. Do not share it with anyone.\n" . $signature . "\n";
                // $response = $this->twilio->sendSms($to, $message);
                $this->emailService->sendEmail(
                    $request->user(),
                    $user->email,
                    $subject,
                    $body,
                    $ipAddress
                );
                return response()->json([
                    'status' => true,
                    'httpStatus' => 200,
                    'token' => $token,
                    // 'expires_at' => $carbon_date->format('Y-m-d H:i:s'),
                    'UserData' => $UserData,
                    'message' => 'Registration Successfully',
                ]);
                // return response()->json([
                //     'status' => [
                //         'success' => true,
                //         'httpStatus' => 200,
                //     ],
                //     'id' => $user->id,
                //     'name' => $user->name,
                //     'username' => $user->email,
                //     'token' => $token,
                //     'user' => $user,
                //     'message' => 'Registration Successfully',
                // ]);
            } catch (\Throwable $th) {
                DB::rollback();
                return [
                    'status' => [
                        'success' => false,
                        'httpStatus' => 500,
                    ],
                    'message' => $th->getMessage(),
                ];
            }
        }
    }
    public function login(Request $request)
    {
        if ($request->isMethod('post')) {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:191|exists:users,email,active,1',
                'password' => 'required',
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
                    'status' => false,
                    'httpStatus' => 201,
                    'message' => implode(',', $errorArray),
                    'error' => $validator->messages(),
                ]);
            } else {
                $token = trim($request->bearerToken());
                $apiKey = $request->header('apiKey') ?? $token;
                $userAccess = User::where('api_token', hash('sha256', $apiKey))->first();
                $AgencyID = ($userAccess->UserType == 1) ? $userAccess->id : $userAccess->AgencyID;
                $request->merge(['AgencyID' => $AgencyID]);
                $signa = ($AgencyID == 97) ? 'GoerTrip' : 'Portal';
                $RequestUserType = $request->UserType ?? 0; // 1 =  For b2c login

                $user = User::with('details.RMUser:id,title,name,email,mobile')->where('email', $request->email)
                    ->where(function ($q) {
                        $q->where('UserType', 0)->orWhere('UserType', 1);
                    })->where(function ($q) use ($userAccess, $RequestUserType, $AgencyID) {
                        if ($userAccess->UserType == 1 && $RequestUserType == 0) {
                            $q->where('id', $userAccess->id);
                        } else {
                            $q->where('AgencyID', $AgencyID);
                        }
                    })->first();

                if (!$user || !Hash::check($request->password, $user->password)) {
                    return response()->json([
                        'status' => false,
                        'httpStatus' => 401,
                        'message' => '⚡We’ve upgraded! If login isn’t working, please tap Forgot Password to reset and continue using ' . $signa,
                    ]);
                } else {
                    // $user->tokens()->delete();
                    // $carbon_date = Carbon::now();
                    // $carbon_date->addHours(12);
                    // print_r($carbon_date->format('Y-m-d H:i:s'));die;
                    // $token = $user->createToken($user->email . '_Token')->plainTextToken;
                    if ($user?->details?->RMUser) {
                        $user->details->RMUser->makeHidden(['details']);
                    }
                    $token = $user->createToken('auth_token')->plainTextToken;
                    $carbon_date = Carbon::parse(Carbon::now());
                    $carbon_date->addHours(12);

                    // $BalanceWallet = WalletModel::WalletBalance($user, $user->id);
                    // $BNPLsums = $this->bnplService->sumOfCreditLimit($user, $user->id);
                    // $bookable_balance = isset($BalanceWallet['balances']['bookable_balance']) ? $BalanceWallet['balances']['bookable_balance'] : 0;
                    $UserData = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->email,
                        // 'accountBalance' => $bookable_balance, //$user->WalletBalance,
                        'WalletStatus' => $user->WalletStatus,
                        'CreditLimitStatus' => $user->CreditLimitStatus,
                        'BNPLCreditStatus' => $user->BNPLCreditStatus,
                        'email' => $user->email,
                        'mobile' => $user->mobile,
                        'address' => $user->address,
                        // 'BNPL' => $BNPLsums,
                        // 'DueDate' => isset($BalanceWallet['balances']['DueDate']) ? $BalanceWallet['balances']['DueDate'] : '',
                        // 'totalOutStanding' => isset($BalanceWallet['balances']['totalOutStanding']) ? $BalanceWallet['balances']['totalOutStanding'] : 0,
                        'user' => $user,
                    ];

                    // $UserData = [
                    //     'id' => $user->id,
                    //     'name' => $user->name,
                    //     'username' => $user->email,
                    //     'accountBalance' => $user->WalletBalance,
                    //     'WalletStatus' => $user->WalletStatus,
                    //     'email' => $user->email,
                    //     'mobile' => $user->mobile,
                    //     'address' => $user->address,
                    // ];
                    return response()->json([
                        'status' => true,
                        'httpStatus' => 200,
                        'token' => $token,
                        'expires_at' => $carbon_date->format('Y-m-d H:i:s'),
                        'UserData' => $UserData,
                        'message' => 'Logged In Successfully',
                    ]);
                }
            }
        } else {
            return response()->json([
                'status' => false,
                'httpStatus' => 405,
                'message' => 'The GET method is not supported',
            ]);
        }
    }

    public function agentlogin(Request $request)
    {
        if ($request->isMethod('post')) {
            // $validator = Validator::make($request->all(), [
            //     'email' => 'required|max:191',
            //     'password' => 'required'
            // ]);
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:191|exists:users,email,active,1',
                'password' => 'required'
            ], [
                'email.exists' => 'The provided email does not exist or the account is not active.'
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
                    'status' => false,
                    'httpStatus' => 201,
                    'message' => implode(',', $errorArray),
                    'error' => $validator->messages(),
                ]);
            } else {
                $franchise = isset($request->franchise) ? $request->franchise : false;
                $apiKey = $request->header('apiKey');
                $userAccess = User::where('api_token', hash('sha256', $apiKey))->first();
                $AgencyID = ($userAccess->UserType == 1) ? $userAccess->id : $userAccess->AgencyID;
                $request->merge(['AgencyID' => $AgencyID]);
                $signa = ($AgencyID == 97) ? 'GoerTrip' : 'Portal';
                // pr($request->all());die;
                if (!Auth::attempt($request->only('email', 'password', 'AgencyID'))) {
                    return response()->json([
                        'status' => false,
                        'httpStatus' => 401,
                        'message' => '⚡We’ve upgraded! If login isn’t working, please tap Forgot Password to reset and continue using ' . $signa,
                    ]);
                } else {
                    $user = User::with('details.RMUser:id,title,name,email,mobile')->where('AgencyID', $AgencyID)->where('email', $request->email)
                        ->where(function ($q) use ($franchise) {
                            if ($franchise == 1) {
                                $q->whereIn('UserType', [6, 7]);
                            } else {
                                $q->where('UserType', 2);
                            }
                        })->first();
                    if ($user?->details?->RMUser) {
                        $user->details->RMUser->makeHidden(['details']);
                    }
                    if ($user) {
                        $CardDetails = LoyaltyUserCard::getUserCardDetails($user);

                        $ScanPayActive = userDevices::where('AgencyID', $user->AgencyID)->where('user_id', $user->id)->where('is_verified', 1)->exists();
                        $StoreExist = Store::where('AgencyID', $user->AgencyID)->where('UserSysId', $user->id)->exists();

                        // $user->tokens()->delete();
                        // $carbon_date = Carbon::now();
                        // $carbon_date->addHours(12);
                        // print_r($carbon_date->format('Y-m-d H:i:s'));die;
                        // $token = $user->createToken($user->email . '_Token')->plainTextToken;
                        $token = $user->createToken('auth_token')->plainTextToken;
                        // $carbon_date = Carbon::parse(Carbon::now());
                        // $carbon_date->addHours(12);
                        $BalanceWallet = WalletModel::WalletBalance($request->user(), $user->id);
                        $BNPLsums = $this->bnplService->sumOfCreditLimit($request->user(), $user->id);
                        $bookable_balance = isset($BalanceWallet['balances']['bookable_balance']) ? $BalanceWallet['balances']['bookable_balance'] : 0;
                        $UserData = [
                            'id' => $user->id,
                            'name' => $user->name,
                            'username' => $user->email,
                            'accountBalance' => $bookable_balance, //$user->WalletBalance,
                            'WalletStatus' => $user->WalletStatus,
                            'CreditLimitStatus' => $user->CreditLimitStatus,
                            'BNPLCreditStatus' => $user->BNPLCreditStatus,
                            'email' => $user->email,
                            'mobile' => $user->mobile,
                            'address' => $user->address,
                            'BNPL' => $BNPLsums,
                            'ScanPayActive' => ($ScanPayActive) ? true : false,
                            'IsStore' => ($StoreExist) ? true : false,
                            'DueDate' => isset($BalanceWallet['balances']['DueDate']) ? $BalanceWallet['balances']['DueDate'] : '',
                            'totalOutStanding' => isset($BalanceWallet['balances']['totalOutStanding']) ? $BalanceWallet['balances']['totalOutStanding'] : 0,
                            'user' => $user,
                        ];
                        return response()->json([
                            'status' => true,
                            'httpStatus' => 200,
                            'token' => $token,
                            // 'expires_at' => $carbon_date->format('Y-m-d H:i:s'),
                            'UserData' => $UserData,
                            'CardDetails' => $CardDetails,
                            'message' => 'Logged In Successfully',
                        ]);
                    } else {
                        return response()->json([
                            'status' => false,
                            'httpStatus' => 401,
                            'message' => '⚡We couldn’t find this user. Please reach out to our support team for help. ' . $signa,
                        ]);
                    }
                }
            }
        } else {
            return response()->json([
                'status' => false,
                'httpStatus' => 405,
                'message' => 'The GET method is not supported',
            ]);
        }
    }

    public function logout()
    {
        auth()->user()->tokens()->delete();
        return response()->json([
            'status' => true,
            'httpStatus' => 200,
            'message' => 'Logout Successfully',
        ]);
    }
    public function updateprofile(Request $request)
    {
        $input = $request->all();
        // Validate the data submitted by user
        $validator = Validator::make($request->all(), [
            'title' => 'required|max:255',
            'fname' => 'required|max:255',
            'lname' => 'required|max:255',
            'name' => 'required|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'httpStatus' => 201,
                'message' => 'Fill all required filed',
                'error' => $validator->messages(),
            ]);
        } else {
            $userId = $request->user()->id;
            $user = User::findOrFail($request->user()->id);
            // $user = auth()->user();
            $companydetails = incorporation_details::where('UserSysId', $request->user()->id)->where('AgencyID', $user->AgencyID)->first();

            // return response()->json([
            //     'status' => false,
            //     'httpStatus' => 200,
            //     'message' => 'Update Successfully',
            //     'logo' => $request->file('logo'),
            //     'input' => $input,
            // ]);
            //print_r(auth()->user()->currentAccessToken()->token);die;

            if ($user->UserType == 0) {
                $user->name = $request->fname . ' ' . $request->lname;
            } else {
                $user->name = $request->name;
            }

            $user->fname = $request->fname;
            $user->lname = $request->lname;
            $user->title = $request->title;
            if (isset($request->dob) && !empty($request->dob)) {
                $user->dateofbirth = Helper::getDateFormate(trim($request->dob));
            }
            if (isset($request->dob) && !empty($request->dob)) {
                $user->dateofbirth = Helper::getDateFormate(trim($request->dob));
            }
            if (isset($request->passexp) && !empty($request->passexp)) {
                $user->passexp = Helper::getDateFormate(trim($request->passexp));
            }
            if (isset($request->passisse) && !empty($request->passisse)) {
                $user->passisse = Helper::getDateFormate(trim($request->passisse));
            }
            if (isset($request->passno) && !empty($request->passno)) {
                $user->passno = $request->passno;
            }
            if (isset($request->panno) && !empty($request->panno)) {
                $user->panno = $request->panno;
            }
            if (isset($request->passnational) && !empty($request->passnational)) {
                $user->passnational = $request->passnational;
            }

            $user->save();

            $fileName = '';
            $fileNameProof = '';
            if ($request->file('profilepicture')) {
                $fileName = time() . '.' . $request->profilepicture->extension();
                $destinationPath = public_path('storage/upload/' . $userId . '/profilepicture/');
                $request->file('profilepicture')->move($destinationPath, $fileName);
            }
            if ($request->file('addressproof')) {
                $fileNameProof = time() . '.' . $request->addressproof->extension();
                $destinationPath = public_path('storage/upload/' . $userId . '/document/');
                $request->file('addressproof')->move($destinationPath, $fileNameProof);
            }

            $Insert = array(
                'UserSysId' => $user->id,
                'AgencyID' => $user->AgencyID,
                // 'mobile' => $request->companymobile,
                // 'email' => $request->companyemail,
                'agencyName' => $request->name,
                // 'tax_number' => $request->tax_number,
                'profilepicture' => (isset($fileName) && !empty($fileName)) ? $fileName : @$companydetails->logo,
                // 'address' => $request->address,
                // 'address1' => $request->address1,
                // 'country' => $request->country,
                // 'city' => $request->city,
                // 'pincode' => $request->pincode,
                // 'addressproof' => (isset($fileNameProof) && !empty($fileNameProof)) ? $fileNameProof : @$companydetails->addressproof,
            );
            if ($companydetails) {
                $Insert['updated_at'] = date('Y-m-d H:i:s');
                incorporation_details::where('UserSysId', $userId)->where('AgencyID', $user->AgencyID)->update($Insert);
            } else {
                $Insert['created_at'] = date('Y-m-d H:i:s');
                $Insert['updated_at'] = date('Y-m-d H:i:s');
                incorporation_details::insertGetId($Insert);
            }
            // pr($user);
            // pr($companydetails);
            // die;
            return response()->json([
                'status' => true,
                'httpStatus' => 200,
                'user' => $request->user(),
                'message' => 'Update Successfully',
            ]);
        }
    }

    public function userdetails()
    {
        $user = auth()->user();
        if ($user) {
            $Logo = $this->APP_URL . '/storage/upload/' . $user->id . '/logo/' . $user->details->logo;
            $CardDetails = LoyaltyUserCard::getUserCardDetails($user);
            $ScanPayActive = userDevices::where('AgencyID', $user->AgencyID)->where('user_id', $user->id)->where('is_verified', 1)->exists();
            $dataArray = [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->email,
                'accountBalance' => $user->WalletBalance,
                'WalletStatus' => $user->WalletStatus,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'address' => $user->address,
                'IsActive' => $user->active,
                'ScanPayActive' => ($ScanPayActive) ? true : false,
                'user' => $user,
                'public_logo' => $Logo,
                // 'public_logo' => url('upload/logo/' . $user->id . '/' . $user->details->logo),
            ];
            return response()->json([
                'httpStatus' => 200,
                'status' => true,
                'UserData' => $dataArray,
                'CardDetails' => $CardDetails,
                'message' => 'SUCCESS',
            ]);
        } else {
            return response()->json([
                'UserData' => $user,
                'status' => false,
                'httpStatus' => 401,
                'message' => 'Un-Authorised',
            ]);
        }
    }

    public function createcustomer(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $userId = isset($request->id) ? $request->id : "";
                if ($request->UserType == 2) {
                    $validator = Validator::make($request->all(), [
                        'fname' => 'required|string|max:255',
                        'lname' => 'required|string|max:255',
                        'UserType' => 'required',
                        // 'countrycode' => 'required',
                        'agencyName' => 'required|string|max:255',
                        'mobile' => ['required', 'digits_between:9,12', Rule::unique('users', 'mobile')->ignore($userId)],
                        'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
                        //'password' => ['confirmed', Rules\Password::defaults()],
                        //'password' => ['required', 'confirmed', Rules\Password::defaults()],
                    ]);
                } else {
                    $validator = Validator::make($request->all(), [
                        'fname' => 'required|string|max:255',
                        'lname' => 'required|string|max:255',
                        'UserType' => 'required',
                        // 'countrycode' => 'required',
                        'mobile' => ['required', 'digits_between:9,12', Rule::unique('users', 'mobile')->ignore($userId)],
                        'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
                        //'password' => ['confirmed', Rules\Password::defaults()],
                        //'password' => ['required', 'confirmed', Rules\Password::defaults()],
                    ]);
                }


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
                if ($userId && $userId != "") {
                    $insertGetId = User::where('id', $userId)->update([
                        'name' => ($request->UserType == 2) ? $request->agencyName : $request->fname . ' ' . $request->lname,
                        'fname' => $request->fname,
                        'lname' => $request->lname,
                        'mobile' => $request->mobile,
                        'UserType' => $request->UserType,
                        'title' => isset($request->title) ? $request->title : '',
                        'countrycode' => isset($request->countrycode) ? $request->countrycode : '91',
                        'PaxType' => 1,
                        'active' => 1,
                        'email' => $request->email,
                        'AgencyID' => ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID,
                        'UserSysId' => $request->user()->id,
                        'password' => (isset($request->password) && !empty($request->password)) ? Hash::make($request->password) : Hash::make(Str::random(10)),
                        // 'created_at' =>  date('Y-m-d H:i:s'),
                        'updated_at' =>  date('Y-m-d H:i:s'),
                    ]);
                    $user = $result = true;
                    $message = "User Updated Succesfully!";
                } else {

                    $insertGetId = User::insertGetId([
                        'name' => ($request->UserType == 2) ? $request->agencyName : $request->fname . ' ' . $request->lname,
                        'fname' => $request->fname,
                        'lname' => $request->lname,
                        'mobile' => $request->mobile,
                        'UserType' => $request->UserType,
                        'title' => isset($request->title) ? $request->title : '',
                        'countrycode' => isset($request->countrycode) ? $request->countrycode : '91',
                        'PaxType' => 1,
                        'active' => 1,
                        'email' => $request->email,
                        'AgencyID' => ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID,
                        'UserSysId' => $request->user()->id,
                        'password' => (isset($request->password) && !empty($request->password)) ? Hash::make($request->password) : Hash::make(Str::random(10)),
                        'created_at' =>  date('Y-m-d H:i:s'),
                        'updated_at' =>  date('Y-m-d H:i:s'),
                    ]);
                    $user = User::find($insertGetId);
                    // event(new Registered($user));
                    $InsertIncorpo = array(
                        'UserSysId' => $user->id,
                        'AgencyID' => $user->AgencyID,
                        'mobile' => $request->mobile,
                        'email' => $request->email,
                        'agencyName' => ($request->UserType == 2) ? $request->agencyName : $request->fname . ' ' . $request->lname,
                        'leadsource' => isset($request->leadsource) ? $request->leadsource : 1,
                        'Isonboarding' => ($request->UserType == 2) ? 0 : 5,
                        'onboarding_process' => ($request->UserType == 2) ? 10 : 100,
                        'tax_number' => '',
                        'logo' => '',
                        'address' => '',
                        'address1' => '',
                        'country' => '',
                        'city' => '',
                        'pincode' => '',
                        'addressproof' => '',
                        'created_at' =>  date('Y-m-d H:i:s'),
                        'updated_at' =>  date('Y-m-d H:i:s'),
                    );
                    $result = incorporation_details::insertGetId($InsertIncorpo);

                    $message = "User Created Succesfully!";
                }
                if ($user && $result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => $message,
                        'result' => $user,
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


    public function fetchcustomer(Request $request)
    {


        try {
            if ($request->isMethod('post')) {
                $userIds = isset($request->userIds) ? $request->userIds : [];
                $keyword = (isset($request->keyword) && !empty($request->keyword)) ? $request->keyword : '';
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $result = User::select('users.*', 'users.name as agencyName')->where(function ($query) use ($user) {
                    if ($user->UserType == 1) {
                        $query->where('AgencyID', $user->id);
                    } else {
                        $query->where('UserSysId', $user->id);
                    }
                })->where(function ($query) {  // This ensures both conditions are grouped
                    $query->where('UserType', 0)
                        ->orWhere('UserType', 2);
                })->where(function ($query) use ($keyword) {
                    if ($keyword !== "null" && !empty(trim($keyword))) {
                        $query->where('name', "like", "%" . $keyword . "%");
                        $query->orWhere('fname', "like", "%" . $keyword . "%");
                        $query->orWhere('lname', "like", "%" . $keyword . "%");
                        $query->orWhere('mobile', "like", "%" . $keyword . "%");
                        $query->orWhere('email', "like", "%" . $keyword . "%");
                    }
                })->where(function ($query) use ($userIds) {
                    if (!empty($userIds)) {
                        // $query->whereNotIn('id', $userIds);
                    }
                })->paginate($perPage);

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

    public function importcustomer(Request $request)
    {

        try {

            if ($request->isMethod('post')) {

                $usersData = $request->all();
                // return response()->json(["data" => ($usersData)]);

                $results = [];
                $errors = [];

                foreach ($usersData as $data) {
                    // return $data;
                    // Initialize validator for each record
                    $validator = Validator::make($data, [
                        'firstname' => 'required|string|max:255',
                        'lastname' => 'required|string|max:255',
                        'UserType' => 'required',
                        'mobile' => 'required|digits_between:9,12',
                        'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
                    ]);

                    if ($validator->fails()) {
                        $errors[] = [
                            'user' => $data,
                            'errors' => $validator->messages()
                        ];
                        continue; // Skip this record if validation fails
                    }

                    // Check if email already exists
                    $existingUser = User::where('email', $data['email'])->first();
                    if ($existingUser) {
                        // Skip this record if email is not unique
                        $errors[] = [
                            'user' => $data,
                            'errors' => ['email' => 'Email already exists']
                        ];
                        continue;
                    }

                    // Proceed with inserting the valid user data
                    $insertGetId = User::insertGetId([
                        'name' => $data['UserType'] == 2 ? $data['agencyName'] : $data['firstname'] . ' ' . $data['lastname'],
                        'fname' => $data['firstname'],
                        'lname' => $data['lastname'],
                        'mobile' => $data['mobile'],
                        'UserType' => $data['UserType'] == 'b2c' ? '0' : '2',
                        'email' => $data['email'],
                        'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                        'UserSysId' => $request->user()->id,
                        'password' => isset($data['password']) && !empty($data['password']) ? Hash::make($data['password']) : Hash::make(Str::random(10)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Additional information can be inserted as needed
                    $user = User::find($insertGetId);
                    $InsertIncorpo = [
                        'UserSysId' => $user->id,
                        'AgencyID' => $user->AgencyID,
                        'mobile' => $data['mobile'],
                        'email' => $data['email'],
                        'agencyName' => $data['UserType'] == 2 ? $data['agencyName'] : $data['firstname'] . ' ' . $data['lastname'],
                        'leadsource' => isset($data['leadsource']) ? $data['leadsource'] : 1,
                        'Isonboarding' => $data['UserType'] == 2 ? 0 : 5,
                        'onboarding_process' => $data['UserType'] == 2 ? 10 : 100,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $result = incorporation_details::insertGetId($InsertIncorpo);

                    // Store the result of the insertion
                    $results[] = $user;
                }

                if (count($results) > 0) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Users created successfully!',
                        'result' => $results,
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 400,
                        ],
                        'message' => 'No valid users to create',
                        'errors' => $errors,
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


    public function compareFaces(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image_base64_1' => 'required',
            'image_base64_2' => 'required'
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
        // $image1 = $request->input('image_base64_1'); // base64 image
        // $image2 = $request->input('image_base64_2'); // base64 image

        // $image1 = str_replace('data:image/jpeg;base64,', '', $request->input('image_base64_1'));
        // $image2 = str_replace('data:image/jpeg;base64,', '', $request->input('image_base64_2'));
        // $response = Http::asForm()->post('https://api-us.faceplusplus.com/facepp/v3/compare', [
        //     'api_key' => env('FACEPP_API_KEY'),
        //     'api_secret' => env('FACEPP_API_SECRET'),
        //     'image_base64_1' => $image1,
        //     'image_base64_2' => $image2,
        // ]);



        $image1 = $request->input('image_base64_1');
        $image2 = $request->input('image_base64_2');

        // Strip base64 prefix if present
        $image1 = preg_replace('/^data:image\/\w+;base64,/', '', $image1);
        $image2 = preg_replace('/^data:image\/\w+;base64,/', '', $image2);

        // Optional: Resize images for better detection
        // try {
        //     $img1 = Image::make(base64_decode($image1))->resize(500, 500)->encode('jpg');
        //     $img2 = Image::make(base64_decode($image2))->resize(500, 500)->encode('jpg');
        //     $image1 = base64_encode($img1);
        //     $image2 = base64_encode($img2);
        // } catch (\Exception $e) {
        //     return response()->json(['error' => 'Image processing failed'], 400);
        // }

        $response = Http::asForm()->post('https://api-us.faceplusplus.com/facepp/v3/compare', [
            'api_key' => env('FACEPP_API_KEY'),
            'api_secret' => env('FACEPP_API_SECRET'),
            'image_base64_1' => $image1,
            'image_base64_2' => $image2,
        ]);

        if ($response->failed()) {
            return response()->json(['error' => $response->json()], 500);
        }

        return $response->json();


        return response()->json($response->json());
    }
    public function generateApiKey(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id'
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
        $user = User::find($request->user_id);
        $token = Str::random(80);
        $user->forceFill([
            'api_token' => hash('sha256', $token),
        ])->save();

        incorporation_details::where('UserSysId', $request->user_id)->update(['api_key' => $token]);
        return response()->json([
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'message' => 'Store this API key securely. It will not be shown again.',
            'api_key' => $token,
        ]);
    }
    public function ForgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|exists:users,email'
        ]);
        $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
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

        $user = User::where('AgencyID', $AgencyID)->where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 404,
                ],
                'message' => 'User not found',
            ]);
        }

        $phone = '+' . $user->countrycode . $user->mobile;
        $ipAddress = $request->ip();
        $signature = substr($request->user()->name, 0, 8);
        $OTP = random_int(100000, 999999);
        $message = "Dear Travel Partner, your OTP to verify mobile " . $OTP . ". DO NOT SHARE the OTP. If you did not request an OTP contact us on our support number. Team CRMOZC";
        //$message = "Dear User, your OTP for CRM access is " . $OTP . ". DO NOT SHARE the OTP. If you did not request an OTP contact us on our support number. Team CRMOZC";
        //$message = "Your OTP for user validation is " . $OTP . ".  \nValid for 15 minutes. Do not share it with anyone.\n" . $signature . "\n";

        $otpRequest = $this->otpService->generateOtpNew(
            $request->user(),
            $phone,
            $message,
            $ipAddress,
            $OTP
        );
        // pr($request->user());
        // die;
        // if ($AgencyID == 97 && $request->user()->UserType == 1) {
        //     $to_email = 'goertripv2@outlook.com';
        // } else {
        $to_email = $user->email;
        //}

        $data = [
            'OTPS' => $OTP,
            'logo' => GetLogo($user),
        ];
        $subject = 'OTP for User Account Verification';
        $ipAddress = $request->ip();
        $body = view('emails.otp_send', $data)->render();
        $emailRequest = $this->emailService->sendEmail(
            $user,
            $to_email,
            $subject,
            $body,
            $ipAddress
        );
        // pr($otpRequest);
        // pr($emailRequest);
        // die;

        if (isset($otpRequest['status']['success']) && $otpRequest['status']['success'] == 1) {
            $token = encrypts($otpRequest['otpRequestID'], $this->SECURITYKEY, $this->SECURITYKEY);
            PasswordResetTokens::updateOrInsert(
                ['email' => $user->email],
                ['token' => $token, 'created_at' => now()]
            );
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'OTP generated successfully',
                'token' => $token,
                'mobile' => $phone,
            ]);
        }
    }

    public function ResetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => 'required|string',
            'phone' => 'required|string',
            'email' => 'required|email|exists:users,email',
            'otp' => [
                'required',
                'digits:6',
                function ($attribute, $value, $fail) {
                    // Prevent common weak OTP patterns
                    if (preg_match('/^(\d)\1+$/', $value)) { // All same digits
                        $fail('The OTP is too predictable.');
                    }
                    if (is_numeric($value) && in_array($value, ['123456', '654321', '111111', '000000'])) {
                        $fail('The OTP is too common.');
                    }
                },
            ],
            'password' => [
                'required',
                'confirmed',
                'min:8',
                'max:64',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]+$/'
            ],
        ], [
            'email.exists' => 'The provided email does not exist in our system.',
            'otp.digits' => 'The OTP must be exactly 6 digits.',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number and one special character.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.max' => 'Password may not be greater than 64 characters.',
        ]);
        $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
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

        $passwordReset = PasswordResetTokens::where('email', $request->email)->where('token', $request->reset_token)->first();

        if (!$passwordReset) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 404,
                ],
                'message' => 'Invalid reset token.',
            ]);
        }
        $tokenCreatedAt = Carbon::parse($passwordReset->created_at);
        if ($tokenCreatedAt->diffInMinutes(Carbon::now()) > 15) {
            PasswordResetTokens::where('email', $request->email)->delete();
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 403,
                ],
                'message' => 'The OTP has expired. Please request a new one.',
            ]);
        }
        $user = $request->user();
        $verified = $this->otpService->verifyOtp(
            $user,
            $request->phone,
            $request->otp
        );
        if ($verified == 1) {
            // Reset password
            $user = User::where('AgencyID', $AgencyID)->where('email', $request->email)->first();
            $user->password = Hash::make($request->password);
            $user->save();
            // Clean up
            PasswordResetTokens::where('email', $request->email)->delete();
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Password reset successfully!',
            ]);
        } else {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 400,
                ],
                'message' => 'Invalid OTP or mobile number',
            ]);
        }
    }

    public function devicesVerify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|max:255',
            'device_name' => 'required|max:255',
            'PassCode' => 'required|digits:6',
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
        } else {
            try {
                userDevices::where('AgencyID', $request->user()->AgencyID)->where('user_id', $request->user()->id)->where('is_verified', 1)->delete();
                $user = User::findOrFail($request->user()->id);
                userDevices::create([
                    'AgencyID' => $request->user()->AgencyID,
                    'user_id' => $request->user()->id,
                    'device_id' => $request->device_id,
                    'device_name' => $request->device_name,
                    'os' => $request->device_name,
                    'is_verified' => 1,
                ]);

                $user->WalletPassCode = Hash::make($request->PassCode);
                $user->save();
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'message' => 'Device Verify Successfully',
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 500,
                    ],
                    'message' => 'Device verifation failed',
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
