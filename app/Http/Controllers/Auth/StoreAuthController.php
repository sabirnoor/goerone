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

class StoreAuthController extends Controller
{
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

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);
        $token = trim($request->bearerToken());
        if (!$token || !$user = User::where('api_token', hash('sha256', $token))->first()) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated', 'httpStatus' => 401, 'details' => 'Access denied: Invalid or unauthorized API key'], 401);
            // return response()->json(['error' => 'Invalid API key'], 401);
        }
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        if (isset($request->logintype) && (int)$request->logintype === 1) {

            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);
            $user = User::where('AgencyID', $AgencyID)->where('email', $request->email)->where(function ($query) {
                $query->where('UserType', 2)
                    ->orWhere('UserType', 0);
            })->first();
            if ($user && Auth::attempt($credentials, (bool) $request->remember)) {
                $CardDetails = LoyaltyUserCard::getUserCardDetails($user);
                $token = $request->user()->createToken('auth_token')->plainTextToken;
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'user' => $request->user(),
                    'CardDetails' => $CardDetails,
                    'message' => 'Logged In Successfully',
                ]);
            } else {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 401,
                    ],
                    'message' => 'Authorization unsuccessful.Either email id or password is invalid',
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
