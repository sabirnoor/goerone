<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyUserCard;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\OtpService;
use App\Services\EmailService;
use App\Services\TwilioService;

class StoreAuthController extends Controller
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

    /**
     * @deprecated Email/password self-signup. Stores now register via OTP:
     * verifyOtpStore -> startRegistration -> StoreOnboardingController.
     * Remove the route for this method, or keep it for admin tooling only.
     */
    public function register(Request $request)
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

    // public function login(Request $request)
    // {
    //     $IsAdmin = ($request->admin ?? 0);
    //     if ($IsAdmin == 1) {
    //         $request->validate([
    //             'email' => 'required|email',
    //         ]);
    //     } else {
    //         $request->validate([
    //             'email' => 'required|email',
    //             'password' => 'required',
    //         ]);
    //     }


    //     $token = trim($request->bearerToken());

    //     if (!$token || !$user = User::where('api_token', hash('sha256', $token))->first()) {
    //         return response()->json(['status' => false, 'message' => 'Unauthenticated', 'httpStatus' => 401, 'details' => 'Access denied: Invalid or unauthorized API key'], 401);
    //         // return response()->json(['error' => 'Invalid API key'], 401);
    //     }

    //     $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
    //     if (isset($request->logintype) && (int)$request->logintype === 1) {

    //         $credentials = $request->validate([
    //             'email' => 'required|email',
    //             'password' => 'required',
    //         ]);
    //         $user = User::where('AgencyID', $AgencyID)->where('email', $request->email)->where(function ($query) {
    //             $query->where('UserType', 2)
    //                 ->orWhere('UserType', 0);
    //         })->first();
    //         if ($user && Auth::attempt($credentials, (bool) $request->remember)) {
    //             $CardDetails = LoyaltyUserCard::getUserCardDetails($user);
    //             $token = $request->user()->createToken('auth_token')->plainTextToken;
    //             return response()->json([
    //                 'status' => [
    //                     'success' => true,
    //                     'httpStatus' => 200,
    //                 ],
    //                 'access_token' => $token,
    //                 'token_type' => 'Bearer',
    //                 'user' => $request->user(),
    //                 'CardDetails' => $CardDetails,
    //                 'message' => 'Logged In Successfully',
    //             ]);
    //         } else {
    //             return response()->json([
    //                 'status' => [
    //                     'success' => false,
    //                     'httpStatus' => 401,
    //                 ],
    //                 'message' => 'Authorization unsuccessful.Either email id or password is invalid',
    //             ]);
    //         }
    //     }
    //     $store = Store::where('email', $request->email)->first();

    //     if ((!$store || !Hash::check($request->password, $store->password)) && $IsAdmin == 0) {
    //         // throw ValidationException::withMessages([
    //         //     'email' => ['The provided credentials are incorrect.'],
    //         // ]);
    //         return response()->json([
    //             'status' => [
    //                 'success' => false,
    //                 'httpStatus' => 401,
    //             ],
    //             'message' => 'Authorization unsuccessful.Either email id or password is invalid',
    //         ]);
    //     }

    //     if ($store->AgencyID !== $user->id) {
    //         return response()->json([
    //             'status' => [
    //                 'success' => false,
    //                 'httpStatus' => 401,
    //             ],
    //             'message' => 'Authorization unsuccessful.Either email id or password is invalid',
    //         ]);
    //     }

    //     // Inactive stores can't log in on their own (checked after the password, so it isn't revealed to a wrong password).
    //     // Admin "Login as store" ($IsAdmin == 1) is allowed. To block admins too, drop the `$IsAdmin == 0 &&` part.
    //     if ($IsAdmin == 0 && !$store->is_active) {
    //         return response()->json([
    //             'status' => [
    //                 'success' => false,
    //                 'httpStatus' => 403,
    //             ],
    //             'message' => 'This store is inactive. Please contact support.',
    //         ]);
    //     }

    //     // Only the Relationship Manager assigned to this store
    //     $store->store_relation_manager = $store->relation_manager
    //         ? User::select('id', 'name', 'mobile', 'email', 'countrycode')->find($store->relation_manager)
    //         : null;
        
    //     $token = $store->createToken('store-token')->plainTextToken;

    //     return response()->json([
    //         'status' => [
    //             'success' => true,
    //             'httpStatus' => 200,
    //         ],
    //         'access_token' => $token,
    //         'token_type' => 'Bearer',
    //         'store' => $store,
    //         'message' => 'Logged In Successfully',
    //     ]);
    // }

    public function login(Request $request)
    {
        $IsAdmin = ($request->admin ?? 0);

        // API key authentication
        $token = trim((string) $request->bearerToken());

        if (
            !$token ||
            !$user = User::where('api_token', hash('sha256', $token))->first()
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
                'httpStatus' => 401,
                'details' => 'Access denied: Invalid or unauthorized API key'
            ], 401);
        }

        $AgencyID = ($user->UserType == 1)
            ? $user->id
            : $user->AgencyID;

        /*
        |--------------------------------------------------------------------------
        | STORE PHONE OTP LOGIN
        |--------------------------------------------------------------------------
        */

        if ($request->login_type === 'store') {

            $request->validate([
                'phone' => 'required|digits_between:9,12',
            ]);

            // Find store using agency and phone number
            $store = Store::where('AgencyID', $AgencyID)
                ->where('phone', $request->phone)
                ->first();

            // Multiple stores with same phone number
            $storeCount = Store::where('AgencyID', $AgencyID)
                ->where('phone', $request->phone)
                ->count();

            if ($storeCount > 1) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 409,
                    ],
                    'message' => 'Multiple stores are registered with this phone number. Please contact support.',
                ], 409);
            }

            // Only an approved store that admin switched off is blocked.
            // draft / pending / rejected stores must still log in to continue or view onboarding.
            if ($store && $store->isApproved() && !$store->is_active) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 403,
                    ],
                    'message' => 'This store is inactive. Please contact support.',
                ], 403);
            }

            // Generate OTP
            $OTPS = random_int(100000, 999999);
            $ipAddress = $request->ip();
            $data = [
                'OTPS' => $OTPS,
                'logo' => GetLogo($user),
            ];
            // Email OTP
            $to_email = $store?->email; // null for a phone that is not registered yet

            $message = "Dear User, your OTP for CRM access is " . $OTPS . ". DO NOT SHARE the OTP. If you did not request an OTP contact us on our support number. Team CRMOZC";

            // Save OTP using existing OTP service
            $otpRequest = $this->otpService->generateOtp(
                $user,
                $request->phone,
                'Store Login',
                $ipAddress,
                $OTPS,
                $message
            );

            // Send OTP
            $this->twilio->sendSms($request->phone, $message);

            // Send Email (only when the store exists and has an email on file)
            if (!empty($to_email)) {
                $subject = 'OTP for Store Account Verification';
                $body = view('emails.otp_send', $data)->render();
                $this->emailService->sendEmail(
                    $user,
                    $to_email,
                    $subject,
                    $body,
                    $ipAddress
                );
            }

            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'request_id' => $otpRequest->id,
                'store_exists' => (bool) $store,
                'message' => 'OTP generated successfully',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | EXISTING EMAIL / PASSWORD STORE LOGIN
        |--------------------------------------------------------------------------
        */

        if ($IsAdmin == 1) {
            $request->validate([
                'email' => 'required|email',
            ]);
        } else {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);
        }

        // Existing customer/CRM user login
        if (isset($request->logintype) && (int) $request->logintype === 1) {

            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $customerUser = User::where('AgencyID', $AgencyID)
                ->where('email', $request->email)
                ->where(function ($query) {
                    $query->where('UserType', 2)
                        ->orWhere('UserType', 0);
                })->first();

            if ($customerUser && Auth::attempt($credentials, (bool) $request->remember)) {

                $loggedInUser = $request->user();

                $CardDetails = LoyaltyUserCard::getUserCardDetails($loggedInUser);

                $accessToken = $loggedInUser->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'access_token' => $accessToken,
                    'token_type' => 'Bearer',
                    'user' => $loggedInUser,
                    'CardDetails' => $CardDetails,
                    'message' => 'Logged In Successfully',
                ]);
            } else {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 401,
                    ],
                    'message' => 'Authorization unsuccessful. Either email id or password is invalid',
                ]);
            }
        }

        // Existing store email/password login
        $store = Store::where('email', $request->email)->first();

        if (
            (!$store || !Hash::check($request->password, $store->password))
            && $IsAdmin == 0
        ) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 401,
                ],
                'message' => 'Authorization unsuccessful. Either email id or password is invalid',
            ]);
        }

        if (!$store || $store->AgencyID != $user->id) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 401,
                ],
                'message' => 'Authorization unsuccessful. Either email id or password is invalid',
            ]);
        }

        // Inactive stores cannot login on their own
        // Admin login as store is allowed
        if ($IsAdmin == 0 && !$store->is_active) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 403,
                ],
                'message' => 'This store is inactive. Please contact support.',
            ], 403);
        }

        // Relationship Manager details
        $store->store_relation_manager = $store->relation_manager
            ? User::select(
                'id',
                'name',
                'mobile',
                'email',
                'countrycode'
            )->find($store->relation_manager)
            : null;

        // Generate store token
        $accessToken = $store->createToken('store-token')->plainTextToken;

        return response()->json([
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'store' => $store,
            'message' => 'Logged In Successfully',
        ]);
    }


    public function verifyOtpStore(Request $request)
    {
        $request->validate([
            'phone' => 'required|digits_between:9,12',
            'otp' => 'required|digits:6',
        ]);

        $token = trim((string) $request->bearerToken());

        $user = User::where('api_token', hash('sha256', $token))
            ->first();

        if (!$token || !$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $AgencyID = ($user->UserType == 1)
            ? $user->id
            : $user->AgencyID;

        $verified = $this->otpService->verifyOtp(
            $user,
            $request->phone,
            $request->otp
        );

        if (!$verified) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP or phone number',
            ], 400);
        }

        $store = Store::where('AgencyID', $AgencyID)
            ->where('phone', $request->phone)
            ->first();

        // phone verified, but store does not exist -> hand back a short-lived registration token.
        // startRegistration() needs it, so nobody can register a phone they have not verified.
        if (!$store) {
            $registrationToken = Str::random(48);
            Cache::put('store_reg:' . $registrationToken, [
                'phone' => $request->phone,
                'agency_id' => $AgencyID,
            ], now()->addMinutes(30));

            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'verified' => true,
                'loggedin' => false,
                'store_exists' => false,
                'can_register' => true,
                'registration_token' => $registrationToken,
                'message' => 'phone number verified successfully. Please register your store.',
            ]);
        }

        // Only an approved store that admin switched off is blocked.
        if ($store->isApproved() && !$store->is_active) {
            return response()->json([
                'status' => false,
                'message' => 'This store is inactive. Please contact support.',
            ], 403);
        }

        if (!$store->phone_verified_at) {
            Store::where('id', $store->id)->update(['phone_verified_at' => now()]);
            $store->phone_verified_at = now();
        }

        return $this->storeLoginResponse($store, 'OTP verified successfully. Logged In Successfully');
    }

    /**
     * Step after "Yes, I want to register": creates the DRAFT store for a verified phone.
     * Same API-key bearer auth as login / verifyOtpStore (NOT a sanctum route).
     * Route: Route::post('/store/auth/start-registration', [StoreAuthController::class, 'startRegistration']);
     */
    public function startRegistration(Request $request)
    {
        $request->validate([
            'registration_token' => 'required|string|size:48',
        ]);

        $token = trim((string) $request->bearerToken());
        $user = $token ? User::where('api_token', hash('sha256', $token))->first() : null;
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;

        $payload = Cache::get('store_reg:' . $request->registration_token);
        if (!$payload || (int) $payload['agency_id'] !== (int) $AgencyID) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 410,
                ],
                'message' => 'Verification expired. Please verify your mobile number again.',
            ], 410);
        }

        $phone = $payload['phone'];

        // Idempotent: a double tap, or a race, returns the same store instead of a duplicate.
        $store = Store::where('AgencyID', $AgencyID)->where('phone', $phone)->first();

        if (!$store) {
            // Mirrors the defaults createStore() writes so NOT NULL columns are satisfied.
            $id = Store::insertGetId([
                'AgencyID' => $AgencyID,
                'UserSysId' => $AgencyID,
                'store_name' => '',
                'phone' => $phone,
                'email' => null,
                'password' => null,
                'settlement_day' => 7,
                'noOfPass' => 0,
                'gatenumber' => '',
                'event_time' => '',
                'entry_time' => '',
                'rewardrequired' => 0,
                'swipelimit' => 0,
                'swipeinyear' => 0,
                'monthlyswipe' => 0,
                'OTPAllowed' => 0,
                'vendortype' => 0,
                'vendor_category' => '',
                'FaceRecognition' => 0,
                'event_date' => now(),
                'lat' => '',
                'lon' => '',
                'placeId' => '',
                // nothing is switched on until admin approves
                'enquiry_enabled' => 0,
                'payment_enabled' => 0,
                'is_active' => 0,
                'registration_status' => Store::STATUS_DRAFT,
                'registration_source' => 'self',
                'onboarding_step' => 0,
                'phone_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $store = Store::find($id);

            DB::table('store_status_logs')->insert([
                'store_id' => $store->id,
                'from_status' => null,
                'to_status' => Store::STATUS_DRAFT,
                'changed_by' => null,
                'reason' => 'Self registration started',
                'created_at' => now(),
            ]);
        }

        Cache::forget('store_reg:' . $request->registration_token);

        return $this->storeLoginResponse($store, 'Registration started');
    }

    /**
     * Shared login response. Only an APPROVED store gets a full token; everyone else gets
     * an 'onboarding' ability token (and EnsureStoreApproved keeps it off the panel routes).
     */
    private function storeLoginResponse(Store $store, string $message)
    {
        $store->store_relation_manager = $store->relation_manager
            ? User::select('id', 'name', 'mobile', 'email', 'countrycode')
                ->find($store->relation_manager)
            : null;

        $accessToken = $store->createToken(
            'store-token',
            $store->isApproved() ? ['*'] : ['onboarding']
        )->plainTextToken;

        return response()->json([
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'store' => $store,
            'verified' => true,
            'loggedin' => true,
            'store_exists' => true,
            // the frontend routes on these
            'registration_status' => $store->registration_status,
            'onboarding_step' => (int) $store->onboarding_step,
            'rejection_reason' => $store->registration_status === Store::STATUS_REJECTED
                ? $store->rejection_reason
                : null,
            'message' => $message,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function profile(Request $request)
    {
        return response()->json($request->user());
    }
}