<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyUserCard;
use App\Models\RewardEarn;
use App\Models\StoresMapping;
use App\Services\OtpService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class EarningController extends Controller
{

    public $APP_URL;
    public $APP_NAME;
    public $API_URL;
    protected $otpService;
    public function __construct(OtpService $otpService)
    {
        $this->APP_NAME = env('APP_NAME');
        $this->APP_URL = env('APP_URL');
        $this->API_URL = env('API_URL');
        $this->otpService = $otpService;
    }

    public function earningbycustomer(Request $request)
    {
        try {
            // return response()->json([
            //     'status' => [
            //         'success' => true,
            //         'httpStatus' => 200,
            //     ],
            //     'message' => 'Success',
            //     'data' => $request->all(),
            // ]);

            if ($request->isMethod('post')) {
                $filters = (isset($request->filters) && !empty($request->filters)) ? $request->filters : [];
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $result = RewardEarn::getEarningByCustomer($user, $perPage, $filters);
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
}
