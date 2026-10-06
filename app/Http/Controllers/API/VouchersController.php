<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Helpers\Helper;
use App\Http\Resources\Voucher\CustomerVoucherResource;
use App\Http\Resources\Voucher\VoucherOrderResource;
use App\Models\CustomerVoucher;
use App\Models\VoucherOrder;
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
            // memberships arrive as a JSON string (FormData), same pattern as stores_id
            $rawMemberships = $request->memberships;
            $membershipIds = is_array($rawMemberships) ? $rawMemberships : (json_decode($rawMemberships ?? '[]', true) ?: []);
            $membershipIds = array_values(array_unique(array_filter(array_map('intval', $membershipIds))));

            $validator = Validator::make($request->all(), [
                'stores_id' => 'required',
                'customer_type' => 'nullable|integer',
                'no_of_voucher' => 'required',
                'discount_value' => 'required',
                'voucher_name' => 'required',
                'terms_condition' => 'required',
                'voucher_price' => 'required|numeric',
                'max_discount_value' => 'required|numeric',
                'redemption_type' => 'required|in:1,2,3', // 1 = online, 2 = offline, 3 = both
                'customer_share' => 'required|numeric|min:0|max:100',
                'owner_share' => 'required|numeric|min:0|max:100',
            ]);

            $validator->after(function ($v) use ($request, $membershipIds) {
                $errs = $v->errors();

                if (count($membershipIds) === 0) {
                    $errs->add('memberships', 'Select at least one membership');
                } elseif (DB::table('loyalty_program')->whereIn('program_id', $membershipIds)->count() !== count($membershipIds)) {
                    $errs->add('memberships', 'Invalid membership selected');
                }

                if (! $errs->hasAny(['voucher_price', 'max_discount_value'])) {
                    $max = (float) $request->max_discount_value;
                    if ($max > (float) $request->voucher_price) {
                        $errs->add('max_discount_value', 'Max discount value cannot be greater than voucher price');
                    }
                    // discount_type 1 = fixed, 2 = percentage
                    if ((int) $request->discount_type === 1 && $max > (float) $request->discount_value) {
                        $errs->add('max_discount_value', 'Max discount value cannot be greater than the fixed discount value');
                    }
                }

                if (! $errs->hasAny(['customer_share', 'owner_share'])) {
                    if (abs(((float) $request->customer_share + (float) $request->owner_share) - 100) > 0.001) {
                        $errs->add('customer_share', 'Customer share and owner share must total 100%');
                    }
                }
            });

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
                                    'redemption_type' => (int) $request->redemption_type,
                                    'customer_share' => $request->customer_share,
                                    'owner_share' => $request->owner_share,
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
                                    $this->syncMemberships($Entry->id, $membershipIds);
                                } elseif ($voucher_id > 0) {
                                    $row = Vouchers::where('id', $voucher_id)->where('AgencyID', $AgencyID)->where('store_id', $vl['id'])->first();
                                    if ($row) {
                                        $row->update($dataSet);
                                        $this->syncMemberships($row->id, $membershipIds);
                                    }
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

    /** Replace the voucher's membership rows */
    private function syncMemberships($voucherId, array $membershipIds): void
    {
        DB::table('voucher_memberships')->where('voucher_id', $voucherId)->delete();
        DB::table('voucher_memberships')->insert(array_map(
            fn($programId) => ['voucher_id' => $voucherId, 'program_id' => $programId],
            $membershipIds
        ));
    }


    public function orders(Request $request)
    {
        $orders = VoucherOrder::with('items')
            ->where('customer_id', $request->user()->id)
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return VoucherOrderResource::collection($orders)->additional(['status' => true]);
    }

    /** Also used by the frontend to poll payment status after returning from the gateway */
    public function order(Request $request, string $orderNo)
    {
        $order = VoucherOrder::with(['items', 'customerVouchers.orderItem'])
            ->where('customer_id', $request->user()->id)
            ->where('order_no', $orderNo)
            ->firstOrFail();

        return (new VoucherOrderResource($order))->additional(['status' => true]);
    }

    /** status = active | used | expired | cancelled (optional) */
    public function vouchers(Request $request)
    {
        $status = $request->query('status');

        $vouchers = CustomerVoucher::with('orderItem')
            ->where('customer_id', $request->user()->id)
            ->when($status === 'active', fn($q) => $q->where('status', 'active')
                ->where(fn($w) => $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', today())))
            ->when($status === 'expired', fn($q) => $q->where(fn($w) => $w->where('status', 'expired')
                ->orWhere(fn($x) => $x->where('status', 'active')->whereDate('valid_to', '<', today()))))
            ->when(in_array($status, ['used', 'cancelled'], true), fn($q) => $q->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return CustomerVoucherResource::collection($vouchers)->additional(['status' => true]);
    }
}
