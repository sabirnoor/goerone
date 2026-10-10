<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PanService;
use App\Models\User;
use App\Models\incorporation_details;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PanController extends Controller
{
    public $APP_URL;
    public $APP_NAME;
    protected $panService;
    public function __construct(
        PanService $panService,
    ) {
        // $this->middleware('permission:Display', ['only' => ['index']]);
        // $this->middleware('permission:Create', ['only' => ['registeruser']]);
        $this->APP_NAME = env('APP_NAME');
        $this->APP_URL = env('APP_URL');
        $this->panService = $panService;
    }

     public function panverify(Request $request)
    {
        try {
            $user = $request->user();
            $request->merge([
                'pan' => strtoupper(trim($request->pan)),
            ]);
            // $validator = Validator::make($request->all(), [
            //     'customer_id' => [
            //         'required',
            //         'integer',
            //         'exists:users,id',
            //         Rule::exists('users', 'id')->where(function ($query) use ($user) {
            //             $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
            //             return $query->where('AgencyID', $AgencyID);
            //         }),
            //     ],
            //     'pan' => 'required|regex:#^[A-Z]{5}[0-9]{4}[A-Z]{1}$#',
            //     // 'name_as_per_pan' => 'required|max:255',
            //     // 'date_of_birth' => 'required|date|date_format:Y-m-d',
            //     // 'reason' => 'required|max:255',
            // ]);


            $validator = Validator::make($request->all(), [
                'customer_id' => [
                    'required',
                    'integer',
                    'exists:users,id',
                    Rule::exists('users', 'id')->where(function ($query) use ($user) {
                        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                        return $query->where('AgencyID', $AgencyID);
                    }),
                ],
                'pan' => [
                    'required',
                    'regex:#^[A-Z]{5}[0-9]{4}[A-Z]{1}$#',
                    // Rule::unique('pan_requests')->where(function ($query) {
                    //     return $query->where('status', 'success');
                    // }),
                    Rule::unique('pan_requests', 'pan')->where(function ($query) use ($user) {
                        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
                        return $query->where('status', 'success')
                            ->where('user_id', $AgencyID);
                    }),
                    // Rule::unique('users', 'panno'),
                ],
            ], [
                'pan.regex' => 'The PAN number must be in the format ABCDE1234F.',
                'pan.unique' => 'This PAN number is already registered. Please use a different PAN or contact support.',
                'customer_id.exists' => 'The selected customer is invalid.',
            ]);
            $request->merge([
                'entity' => 'in.co.sandbox.kyc.pan_verification.request'
            ]);

            // if fails redirects back with errors
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
            $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
            $dataArray = [
                'user_id' => $request->customer_id,
                'pan' => trim($request->pan),
            ];
            // $dataArray = [
            //     'user_id' => $request->customer_id,
            //     'pan' => $request->pan,
            //     'name_as_per_pan' => $request->name_as_per_pan,
            //     'date_of_birth' => $request->date_of_birth,
            //     'reason' => $request->reason,
            //     '@entity' => $request->entity,
            // ];

            $verified = $this->panService->panVerify(
                $request->user(),
                $dataArray
            );

            $user = User::where('AgencyID', $AgencyID)->findOrFail($request->customer_id);
            if ($verified['status'] == 1) {
                $UpdateUser = User::where('AgencyID', $user->AgencyID)->findOrFail($request->customer_id);
                if (isset($verified['name_as_per_pan']) && !empty($verified['name_as_per_pan'])) {
                    $name = trim($verified['name_as_per_pan'] ?? '');
                    // Split only at the first whitespace
                    $parts = preg_split('/\s+/', $name, 2);
                    $firstName = $parts[0] ?? '';
                    $lastName  = $parts[1] ?? '';
                    $UpdateUser->name = ($verified['name_as_per_pan'] ?? '');
                    $UpdateUser->fname = ($firstName);
                    $UpdateUser->lname = ($lastName);
                }
                $UpdateUser->panno = trim($request->pan);
                $UpdateUser->save();
                $Insert['IsPanVerify'] = 1;
                $Insert['onboarding_process'] = 100;
                $Insert['updated_at'] = date('Y-m-d H:i:s');
                incorporation_details::where('UserSysId', $request->customer_id)->where('AgencyID', $user->AgencyID)->update($Insert);
                unset($verified['response']);
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'message' => 'PAN verify successfully',
                    'verified' => $verified,
                ]);
            } else {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 201,
                    ],
                    'message' => 'Unable to verify. invalid PAN number. please try again with another pan',
                ]);
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
