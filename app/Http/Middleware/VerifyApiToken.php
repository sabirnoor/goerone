<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Models\WalletModel;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

class VerifyApiToken
{
    public function handle($request, Closure $next)
    {
        $token = trim($request->bearerToken());

        $apiKey = $request->header('apiKey');
        if (!$token && isset($request->token) && !empty($request->token)) {
            $token = $request->token;
        }

        if (!$token || !$user = User::where('api_token', hash('sha256', $token))->first()) {
            $accessToken = $this->getUserWithSelectedFields($token);

            if ($apiKey && $accessToken) {
                $AgencyID = ($accessToken->UserType == 1) ? $accessToken->id : $accessToken->AgencyID;
                // $user = User::where('api_token', hash('sha256', $apiKey))->first();
                $user = User::where('api_token', hash('sha256', $apiKey))->where('id', $AgencyID)->first();

                if (!$user) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 401,
                        ],
                        'message' => 'Access denied: Invalid or unauthorized API key',
                    ], 401);
                }
            }
            $routeAction = $request->route()->getActionMethod();
            $UserType = isset($accessToken->UserType) ? $accessToken->UserType : 0;
            if ($accessToken) {
                if ($UserType != 1 && $UserType != 4 && $routeAction != 'initiate_payment' && $routeAction != 'depositrequest' && $routeAction != 'getWalletHistory' && $routeAction != 'getDepositListWithTotals' && $routeAction != 'bookingcalendarAPI') {
                    $transactions = WalletModel::WalletBalance($accessToken, $accessToken->id);
                    $totalOutStanding = isset($transactions['balances']['totalOutStanding']) ? $transactions['balances']['totalOutStanding'] : 0;
                    if ($totalOutStanding > 0) {
                        $DueDate = isset($transactions['balances']['DueDate']) ? $transactions['balances']['DueDate'] : null;
                        $today = Carbon::now();
                        // $targetDate = Carbon::createFromFormat('Y-m-d', $DueDate);
                        if ($today->greaterThan($DueDate)) {
                            return response()->json([
                                'status' => [
                                    'success' => false,
                                    'httpStatus' => 401,
                                ],
                                'message' => 'Your credit limit due date has been exceeded. Kindly settle the outstanding balance at the earliest to avoid service disruption.',
                            ]);
                        }
                    }
                }
            }

            if ($accessToken) {
                auth()->login($accessToken);
                return $next($request);
            }
            return response()->json(['status' => false, 'message' => 'Unauthenticated', 'httpStatus' => 401, 'details' => 'Access denied: Invalid or unauthorized API key'], 401);
            // return response()->json(['error' => 'Invalid API key'], 401);
        }

        auth()->login($user);
        return $next($request);
    }

    public function getUserWithSelectedFields($token)
    {
        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return null;
        }
        // Specify exactly which fields you want
        return $accessToken->tokenable()->select(["*"])->first();
    }
}
