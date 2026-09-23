<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Helpers\Helper;
use App\Models\Vouchers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class VouchersController extends Controller
{

    public $APP_URL;
    public $APP_NAME;
    public function __construct()
    {
        $this->APP_URL = env('APP_URL');
        $this->APP_NAME = env('APP_NAME');
    }

    public function index(Request $request): Response
    {
        die('index');
        return Inertia::render('Bus/Index', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail
        ]);
    }

    public function addnew(Request $request)
    {
        $post = $request->all();

        if ($post && $request->isMethod('post')) {
            $validator = Validator::make($request->all(), [
                'stores_id' => 'required',
                'customer_type' => 'required',
                'no_of_voucher' => 'required',
                'discount_value' => 'required',
                'voucher_name' => 'required',
                'terms_condition' => 'required',
                'voucher_price' => 'required',
                'max_discount_value' => 'required',
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
                // return response()->json([
                //     'status' => [
                //         'success' => false,
                //         'httpStatus' => 201,
                //     ],
                //     'message' => $request->all(),
                // ]);
                DB::beginTransaction();
                try {
                    $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
                    $UserSysId = $request->user()->id;
                    if ($request->stores_id) {
                        $voucher_id = isset($request->id) ? $request->id : 0;
                        $stores_ids = json_decode($request->stores_id, 1);
                        if (count($stores_ids) > 0) {
                            foreach ($stores_ids as $vl) {
                                $exists = false;
                                if ($voucher_id == 0) {
                                    $exists = Vouchers::where('AgencyID', $AgencyID)->where('store_id', $vl['id'])->where('voucher_name', $request->voucher_name)->exists();
                                }
                                $dataSet = [
                                    'store_id' => $vl['id'],
                                    'customer_type' => $request->customer_type,
                                    'AgencyID' => $AgencyID,
                                    'UserSysId' => $UserSysId,
                                    'no_of_voucher' => $request->no_of_voucher,
                                    'discount_value' => $request->discount_value,
                                    'discount_type' => $request->discount_type,
                                    'gtcoin_required' => $request->gtcoin_required ?? 0,
                                    'required_value' => $request->required_value ?? 0,
                                    'is_active' => $request->is_active ?? 0,
                                    'voucher_name' => $request->voucher_name ?? 0,
                                    'voucher_price' => $request->voucher_price ?? 0,
                                    'max_discount_value' => $request->max_discount_value ?? 0,
                                    'terms_condition' => $request->terms_condition ?? 0,
                                    'valid_from' => (!empty($request->valid_from) && $request->valid_from != "null") ? $request->valid_from : null,
                                    'valid_to' => (!empty($request->valid_to) && $request->valid_from != "null") ? $request->valid_to : null,
                                ];

                                if (!$exists && $voucher_id == 0) {
                                    $Entry = Vouchers::create($dataSet);
                                } elseif ($voucher_id > 0) {
                                    Vouchers::where('id', $voucher_id)->where('AgencyID', $AgencyID)->where('store_id', $vl['id'])->update($dataSet);
                                } else {
                                    return [
                                        'status' => [
                                            'success' => false,
                                            'httpStatus' => 2002,
                                        ],
                                        'message' => 'Voucher name is already taken',
                                    ];
                                }
                            }
                        } else {
                            return [
                                'status' => [
                                    'success' => false,
                                    'httpStatus' => 2002,
                                ],
                                'message' => 'Select at least one store Name',
                            ];
                        }
                    }
                    DB::commit();
                    return [
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Add voucher successfully',
                    ];
                } catch (\Exception $e) {
                    DB::rollback();
                    return [
                        'status' => [
                            'success' => false,
                            'httpStatus' => 500,
                        ],
                        'message' => $e->getMessage(),
                    ];
                }
            }
        } else {

            die('Bad request');
        }
    }
    public function voucherList(Request $request)
    {
        $post = $request->all();

        if ($request->isMethod('post')) {
            try {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $result = Vouchers::getVoucher($request->user(), $perPage, $post);
                return [
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'data' => $result,
                    'message' => 'SUCCESS',
                ];
            } catch (\Exception $e) {
                return [
                    'status' => [
                        'success' => false,
                        'httpStatus' => 500,
                    ],
                    'message' => $e->getMessage(),
                ];
            }
        } else {

            die('Bad request');
        }
    }
    public function voucherListAPI(Request $request)
    {
        $post = $request->all();

        if ($request->isMethod('post')) {
            try {

                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $result = Vouchers::getVoucherAPI($request->user(), $perPage, $post);
                return [
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'data' => $result,
                    'message' => 'SUCCESS',
                ];
            } catch (\Exception $e) {
                return [
                    'status' => [
                        'success' => false,
                        'httpStatus' => 500,
                    ],
                    'message' => $e->getMessage(),
                ];
            }
        } else {

            die('Bad request');
        }
    }
}
