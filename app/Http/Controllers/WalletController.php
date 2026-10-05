<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\RewardService;
use Illuminate\Support\Facades\Storage;

class WalletController extends Controller
{
    private $rewardService;
    public function __construct(
        RewardService $rewardService,
    ) {
        $this->rewardService = $rewardService;
    }

    public function getRewardSummary(Request $request)
    {
        $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
        $mode = (isset($request->mode) && $request->mode > 0) ? $request->mode : '';
        if (isset($request->mode) && $request->mode !== 'web') {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|integer|exists:users,id'
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
                    'mode' => $request->mode,
                ]);
            }
        }
        $transactions = User::UserRewardsSummary($request->user(), $request->customer_id)->paginate($perPage);
        return response()->json($transactions);
    }
    public function getRewardHistory(Request $request)
    {
        $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
        $page = (isset($request->page) && $request->page > 0) ? $request->page : 1;
        $mode = (isset($request->mode) && $request->mode > 0) ? $request->mode : '';
        if (isset($request->mode) && $request->mode !== 'web') {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|integer|exists:users,id'
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
                    'mode' => $request->mode,
                ]);
            }
        }
        // $transactions = User::UserRewardsHistory($request->user(), $request->customer_id)->first();
        $transactions = User::UserRewardsHistory($request->user(), $request->customer_id, $perPage, $page);

        return response()->json($transactions);
    }
    public function getWalletBalance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer|exists:users,id'
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

        $transactions = WalletModel::WalletBalance($request->user(), $request->customer_id);
        return response()->json($transactions);
    }

    public function topUpreward(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'payer_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::exists('users', 'id')->where(function ($query) use ($user) {
                    $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                    return $query->where('WalletStatus', 1);
                }),
            ],
            'payee_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::exists('users', 'id')->where(function ($query) use ($user) {
                    $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                    return $query->where('AgencyID', $AgencyID)->where('WalletStatus', 1);
                }),
            ],
            'points' => 'required|numeric|min:0.01',
            'description' => 'nullable|string',
            'ReferenceNo' => 'required|string|max:200',
            'currency' => 'required|string',
        ], [
            'payer_id.exists' => 'The selected payer is not eligible for reward transactions. reward is not active',
            'payee_id.exists' => 'The selected payee is not eligible for reward transactions. reward is not active',
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

        try {
            $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
            $UserSysId = $request->user()->id;
            $validated = $request->all();

            $validated['AgencyID'] = $AgencyID;
            $validated['UserSysId'] = $UserSysId;
            $validated['PlanType'] = 1;
            // pr($validated);
            // die;
            if ($validated['points'] > 0) {
                return DB::transaction(function () use ($validated) {
                    $result = $this->rewardService->addPoints($validated);
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'data' => $result,
                        'message' => 'Reward top-up successful'
                    ]);
                });
            }
        } catch (\Throwable $th) {
            Storage::disk('public')->put('logs/reward/topup/' . $request->ReferenceNo . '/' . date('Y-m-d H:i:s') . '_catchResponse.json', json_encode($th->getMessage()));
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 201,
                ],
                'message' => $th->getMessage(),
            ];
        }
    }
    public function RewardScanPay(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'payer_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::exists('users', 'id')->where(function ($query) use ($user) {
                    $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                    return $query->where('WalletStatus', 1);
                }),
            ],
            'payee_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::exists('users', 'id')->where(function ($query) use ($user) {
                    $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                    return $query->where('WalletStatus', 1);
                }),
            ],
            'points' => 'required|numeric|min:0.01',
            'description' => 'nullable|string',
            // 'ReferenceNo' => 'required|string|max:200',
            'currency' => 'required|string',
        ], [
            'payer_id.exists' => 'The selected payer is not eligible for reward transactions. reward is not active',
            'payee_id.exists' => 'The selected payee is not eligible for reward transactions. reward is not active',
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

        try {
            $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
            $UserSysId = $request->user()->id;
            $validated = $request->all();

            $validated['AgencyID'] = $AgencyID;
            $validated['UserSysId'] = $UserSysId;
            $validated['PlanType'] = 4;

            if ($validated['points'] > 0) {
                return DB::transaction(function () use ($validated) {
                    $result = $this->rewardService->transferPoints($validated);
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'data' => $result,
                        'message' => 'Reward pay successful'
                    ]);
                });
            }
        } catch (\Throwable $th) {
            Storage::disk('public')->put('logs/reward/topup/' . $request->ReferenceNo . '/' . date('Y-m-d H:i:s') . '_catchResponse.json', json_encode($th->getMessage()));
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 201,
                ],
                'message' => $th->getMessage(),
            ];
        }
    }
    public function RewardLedger(Request $request)
    {
        $user = $request->user();
        $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
        $mode = (isset($request->mode) && $request->mode > 0) ? $request->mode : '';
        if (isset($request->mode) && $request->mode !== 'web') {
            $validator = Validator::make($request->all(), [
                'customer_id' => [
                    'required',
                    'integer',
                    'exists:users,id',
                    Rule::exists('users', 'id')->where(function ($query) use ($user) {
                        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                        return $query->where('AgencyID', $AgencyID)->where('WalletStatus', 1);
                    }),
                ]
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
                    'mode' => $request->mode,
                ]);
            }
        }

        try {
            $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
            $UserSysId = $request->user()->id;
            $validated = $request->all();

            $validated['AgencyID'] = $AgencyID;
            $validated['UserSysId'] = $UserSysId;
            $validated['FromDate'] = (isset($request->FromDate)) ? $request->FromDate : null;
            $validated['ToDate'] = (isset($request->ToDate)) ? $request->ToDate : null;
            $validated['bookingID'] = (isset($request->bookingID)) ? $request->bookingID : null;
            return DB::transaction(function () use ($validated, $perPage) {
                $result = $this->rewardService->getCustomerLedger($validated['customer_id'] ?? null, $validated['AgencyID'], $perPage, $validated);
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'data' => $result,
                    'message' => 'Reward ledger successful'
                ]);
            });
        } catch (\Throwable $th) {
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 201,
                ],
                'message' => $th->getMessage(),
            ];
        }
    }
    public function RewardLedgerTemp(Request $request)
    {
        $user = $request->user();
        $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
        $mode = (isset($request->mode) && $request->mode > 0) ? $request->mode : '';
        if (isset($request->mode) && $request->mode !== 'web') {
            $validator = Validator::make($request->all(), [
                'customer_id' => [
                    'required',
                    'integer',
                    'exists:users,id',
                    Rule::exists('users', 'id')->where(function ($query) use ($user) {
                        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                        return $query->where('AgencyID', $AgencyID)->where('WalletStatus', 1);
                    }),
                ]
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
                    'mode' => $request->mode,
                ]);
            }
        }

        try {
            $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
            $UserSysId = $request->user()->id;
            $validated = $request->all();

            $validated['AgencyID'] = $AgencyID;
            $validated['UserSysId'] = $UserSysId;
            $validated['FromDate'] = (isset($request->FromDate)) ? $request->FromDate : null;
            $validated['ToDate'] = (isset($request->ToDate)) ? $request->ToDate : null;
            $validated['bookingID'] = (isset($request->bookingID)) ? $request->bookingID : null;
            return DB::transaction(function () use ($validated, $perPage) {
                $result = $this->rewardService->getCustomerLedgerTemp($validated['customer_id'] ?? null, $validated['AgencyID'], $perPage, $validated);
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'data' => $result,
                    'message' => 'Reward ledger successful'
                ]);
            });
        } catch (\Throwable $th) {
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 201,
                ],
                'message' => $th->getMessage(),
            ];
        }
    }
    public function RewardBalance(Request $request)
    {
        $user = $request->user();
        if (isset($request->mode) && $request->mode !== 'web') {
            $validator = Validator::make($request->all(), [
                'customer_id' => [
                    'required',
                    'integer',
                    'exists:users,id',
                    Rule::exists('users', 'id')->where(function ($query) use ($user) {
                        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                        return $query->where('AgencyID', $AgencyID)->where('WalletStatus', 1);
                    }),
                ]
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
                    'mode' => $request->mode,
                ]);
            }
        }

        try {
            $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
            $UserSysId = $request->user()->id;
            $validated = $request->all();
            $validated['AgencyID'] = $AgencyID;
            return DB::transaction(function () use ($validated) {
                $result = $this->rewardService->getRewardSummary($validated['customer_id'] ?? null, $validated['AgencyID']);
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'data' => $result,
                    'message' => 'Reward balance successful'
                ]);
            });
        } catch (\Throwable $th) {
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 201,
                ],
                'message' => $th->getMessage(),
            ];
        }
    }
}
