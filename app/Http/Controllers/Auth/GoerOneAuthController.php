<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\incorporation_details;
use App\Models\LoyaltyUserCard;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\WebsiteSetting;
use Illuminate\Support\Facades\Auth;
use App\Services\OtpService;
use App\Services\EmailService;
use App\Services\TwilioService;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class GoerOneAuthController extends Controller
{
    protected $otpService;
    protected $emailService;
    protected $twilio;

    public function __construct(
        OtpService $otpService,
        EmailService $emailService,
        TwilioService $twilio,
    ) {
        $this->otpService = $otpService;
        $this->emailService = $emailService;
        $this->twilio = $twilio;
    }
    public function registerddd(Request $request)
    {
        // $validated = $request->validate([
        //     'store_name' => 'required|string|max:255',
        //     'email' => 'required|string|email|max:255|unique:stores',
        //     'password' => 'required|string|min:8|confirmed',
        //     'address' => 'nullable|string',
        //     'phone' => 'nullable|string'
        // ]);

        $validator = Validator::make($request->all(), [
            'store_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:stores',
            'password' => 'required|string|min:8|confirmed',
            'address' => 'nullable|string',
            'phone' => 'nullable|string'
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

        $store = Store::create([
            'store_name' => $request->store_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'address' => $request->address ?? null,
            'phone' => $request->phone ?? null,
        ]);

        $token = $store->createToken('store-token')->plainTextToken;
        return response()->json([
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'access_token' => $token,
            'token_type' => 'Bearer',
            'store' => $store,
            'message' => 'Store Create Successfully',
        ]);
        // return response()->json([
        //     'access_token' => $token,
        //     'token_type' => 'Bearer',
        //     'store' => $store
        // ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'mobile' => 'required|digits_between:9,12',
            'GoerOne' => 'required',
            'UserSysId' => 'nullable',
            'test_login' => 'nullable|boolean',
            'customer_id' => 'nullable|integer',
        ]);
        $token = trim($request->bearerToken());
        if (!$token || !$user = User::where('api_token', hash('sha256', $token))->first()) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated', 'httpStatus' => 401, 'details' => 'Access denied: Invalid or unauthorized API key'], 401);
            // return response()->json(['error' => 'Invalid API key'], 401);
        }
        $user = $request->user();
        $UserSysId = $request->UserSysId ?? 0;
        $ipAddress = $request->ip();
        $OTPS = random_int(100000, 999999);
        $to_email = $user->email;
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        $data = [
            'OTPS' => $OTPS,
            'logo' => GetLogo($user),
        ];
        $subject = 'OTP for User Account Verification';
        $ipAddress = $request->ip();
        $body = view('emails.otp_send', $data)->render();

        // Direct GoerOne test login
        if (
            $request->test_login &&
            !empty($request->customer_id) &&
            $request->GoerOne == 1
        ) {
            $AgencyID = ($user->UserType == 1)
                ? $user->id
                : $user->AgencyID;

            $testUser = User::where('id', $request->customer_id)
                ->where('AgencyID', $AgencyID)
                ->whereIn('UserType', [0, 2])
                ->where('GoerOne', 1)
                ->first();

            if (!$testUser) {
                return response()->json([
                    'status' => false,
                    'message' => 'GoerOne customer not found.'
                ], 404);
            }

            if ((int) $testUser->active !== 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer account is inactive.'
                ], 401);
            }

            $accessToken = $testUser
                ->createToken('goerone-test-token')
                ->plainTextToken;

            return response()->json([
                'status' => true,
                'message' => 'Test login successful.',
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'user' => $testUser,
                'loggedin' => true,
                'test_login' => true,
            ]);
        }

        if (isset($request->mobile) && $request->GoerOne) {
            $to = $request->mobile; // recipient number
            $message = "Dear User, your OTP for CRM access is " . $OTPS . ". DO NOT SHARE the OTP. If you did not request an OTP contact us on our support number. Team CRMOZC";

            $checkuser = User::where('AgencyID', $AgencyID)->where('mobile', $request->mobile)->where(function ($query) {
                $query->where('UserType', 2)
                    ->orWhere('UserType', 0);
            })->where(function ($query) use ($UserSysId) {
                if (!empty($UserSysId) && $UserSysId > 0) {
                    $query->where('id', $UserSysId);
                }
            })->get();

            if (count($checkuser) > 1) {
                $userInfo = collect($checkuser)->map(function ($user) {
                    return [
                        'UserSysId'   => $user['id'] ?? null,
                        'name'   => $user['name'] ?? null,
                        'mobile' => $user['mobile'] ?? null,
                        'email'  => $user['email'] ?? null,
                    ];
                })->values()->toArray();
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'message' => 'Multiple account link on this number. please choose any one to continue.',
                    'multiaccount' => true,
                    'userInfo' => $userInfo,
                ]);
            }

            $firstUser = collect($checkuser)->first();
            if ($firstUser && $firstUser->active === 1) {
                $otpRequest = $this->otpService->generateOtp(
                    $user,
                    $request->mobile,
                    'User Login',
                    $ipAddress,
                    $OTPS,
                    $message
                );
                $response = $this->twilio->sendSms($to, $message);
                $emailRequest = $this->emailService->sendEmail(
                    $user,
                    $to_email,
                    $subject,
                    $body,
                    $ipAddress
                );
                // $token = $firstUser->createToken('auth_token')->plainTextToken;
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'multiaccount' => false,
                    'request_id' => $otpRequest->id,
                    'message' => 'OTP generated successfully',
                ]);
            } else if (count($checkuser) === 0) {
                $otpRequest = $this->otpService->generateOtp(
                    $user,
                    $request->mobile,
                    'User Login',
                    $ipAddress,
                    $OTPS,
                    $message
                );
                $response = $this->twilio->sendSms($to, $message);
                $emailRequest = $this->emailService->sendEmail(
                    $user,
                    $to_email,
                    $subject,
                    $body,
                    $ipAddress
                );
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'multiaccount' => false,
                    'request_id' => $otpRequest->id,
                    'message' => 'OTP generated successfully',
                ]);
            } else {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 401,
                    ],
                    'message' => "Oop's Your account is inactive please contact support to activate account",
                ]);
            }
        }
        $store = Store::where('email', $request->email)->first();

        if (!$store || !Hash::check($request->password, $store->password)) {
            // throw ValidationException::withMessages([
            //     'email' => ['The provided credentials are incorrect.'],
            // ]);
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 401,
                ],
                'message' => 'Authorization unsuccessful.Either email id or password is invalid',
            ]);
        }

        if ($store->AgencyID !== $user->id) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 401,
                ],
                'message' => 'Authorization unsuccessful.Either email id or password is invalid',
            ]);
        }
        $token = $store->createToken('store-token')->plainTextToken;

        return response()->json([
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'access_token' => $token,
            'token_type' => 'Bearer',
            'store' => $store,
            'message' => 'Logged In Successfully',
        ]);
    }

    public function otpverify(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'GoerOne' => 'required',
            'otp' => 'required|digits:6',
            'UserSysId' => 'nullable',
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
                'error' => $validator->messages(),
            ]);
        }
        $user = $request->user();
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        $UserSysId = $request->UserSysId ?? 0;
        $checkuser = User::where('AgencyID', $AgencyID)->where('mobile', $request->mobile)->where(function ($query) {
            $query->where('UserType', 2)
                ->orWhere('UserType', 0);
        })->where(function ($query) use ($UserSysId) {
            if (!empty($UserSysId) && $UserSysId > 0) {
                $query->where('id', $UserSysId);
            }
        })->first();
        if ($checkuser && $checkuser->active === 1) {
            $verified = $this->otpService->verifyOtp(
                $user,
                $request->mobile,
                $request->otp
            );
            $token = $checkuser->createToken('auth_token')->plainTextToken;
            if ($verified && $request->GoerOne) {
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'user' => $checkuser,
                    'loggedin' => true,
                    'message' => 'OTP verified successfully & Logged In Successfully',
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
        } else if (!$checkuser) {
            $verified = $this->otpService->verifyOtp(
                $user,
                $request->mobile,
                $request->otp
            );
            if ($verified) {
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'loggedin' => false,
                    'message' => 'OTP verified successfully',
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
        } else {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 401,
                ],
                'message' => "Oop's Your account is inactive please contact support to activate account",
            ]);
        }
    }
    public function register(Request $request)
    {
        $user = $request->user();
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;

        $validator = Validator::make($request->all(), [
            'GoerOne' => 'required',
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->where(function ($query) use ($AgencyID) {
                        return $query->where('AgencyID', $AgencyID);
                    }),
            ],
            'referral_code' => [
                'nullable',
                'string',
                'size:8',
                Rule::exists(incorporation_details::class, 'referral_code')
                    ->where(fn($q) => $q->where('AgencyID', $AgencyID))
            ],
            'mobile' => [
                'required',
                'digits_between:9,12',
                Rule::unique('users', 'mobile')->where(function ($query) use ($AgencyID) {
                    return $query->where('AgencyID', $AgencyID);
                }),
            ],
            'dob' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'title' => 'required|string'
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
                'error' => $validator->messages(),
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
        try {
            $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
            $leadsource = (int)$request->leadsource ?? 14;

            $referralCode = Str::upper(Str::random(8));
            $referral_code = isset($request->referral_code) ? $request->referral_code : '';
            $referral_user_id = !empty($referral_code) ? incorporation_details::where('AgencyID', $AgencyID)->where('referral_code', $referral_code)->value('UserSysId') : null;
            if ($AgencyID == 97) {
                $referral_earning = 50;
            } else {
                $referral_earning = 0;
            }
            $name = explode(' ', ($request->name ?? ''));
            $insertGetId = User::insertGetId([
                'AgencyID' => $AgencyID,
                'UserSysId' => $request->user()->id,
                'title' => !empty($request->title) ? $request->title : '',
                'name' => $request->name,
                'fname' => $name[0] ?? '',
                'lname' => $name[1] ?? '',
                'mobile' => $request->mobile,
                'countrycode' => 91,
                'UserType' => 0,
                'email' => $request->email,
                'active' => 1,
                'WalletStatus' => 1,
                'GoerOne' => 1,
                'password' => Hash::make($referralCode . $request->mobile . $referralCode),
                'created_at' =>  date('Y-m-d H:i:s'),
                'updated_at' =>  date('Y-m-d H:i:s'),
            ]);
            $user = User::where('AgencyID', $AgencyID)->find($insertGetId);
            $InsertIncorpo = array(
                'UserSysId' => $user->id,
                'AgencyID' => $user->AgencyID,
                'mobile' => $request->mobile,
                'agencyName' => $request->name,
                'email' => $request->email,
                'VIPAgency' => 0,
                'IsMobileVerify' => 1,
                'referral_user_id' => ($referral_user_id > 0) ? $referral_user_id : NULL,
                'referral_code' =>  $referralCode,
                'referral_earning' =>  $referral_earning,
                'leadsource' => $leadsource,
                'onboarding_process' => 100,
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
            $user = User::where('AgencyID', $AgencyID)->find($insertGetId);
            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'loggedin' => true,
                'message' => 'Registration Successfully',
            ]);
        } catch (\Throwable $th) {
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
