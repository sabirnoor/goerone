<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyRedemption;
use App\Models\Store;
use App\Models\StoreSettlementItem;
use App\Services\StoreSettlementService;
use App\Models\StoreSettlements;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreSettlementController extends Controller
{
    protected $settlementService;
    public function __construct(
        StoreSettlementService $settlementService
    ) {
        $this->settlementService = $settlementService;
    }
    public function index(Request $request)
    {
        $User = ($request->user());
        $query = StoreSettlements::with('store:id,store_name,email,phone,type,contact_name')->where(
            function ($query) use ($User) {
                if ($User) {
                    $query->where('AgencyID', $User->AgencyID)->where('customer_id', $User->UserSysId);
                }
            }
        );
        if ($request->status) {
            $query->where('status', $request->status);
        }

        return $query->orderBy('id', 'DESC')->latest()->paginate(50);
    }

    public function show($id)
    {
        return StoreSettlements::with([
            'store',
            'items.redemption',
            'payments'
        ])->findOrFail($id);
    }

    public function generate()
    {
        $stores = Store::all();

        foreach ($stores as $store) {

            $eligibleDate = now()->subDays($store->settlement_day);

            $redemptions = LoyaltyRedemption::where('stores_id', $store->id)->where('AgencyID', $store->AgencyID)
                ->whereNull('settlement_id')
                ->whereDate(
                    'redemption_date',
                    '<=',
                    $eligibleDate
                )->where('discountOwner', '>', 0)->get();

            if ($redemptions->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($store, $redemptions) {

                $totalSales = $redemptions->sum('order_amount');
                $totalDiscount = $redemptions->sum('discountOwner');

                $settlement = StoreSettlements::create([
                    'store_id' => $store->id,
                    'AgencyID' => $store->AgencyID,
                    'customer_id' => $store->UserSysId,
                    'settlement_no' => 'STL' . date('YmdHis') . rand(100, 999),
                    'from_date' => $redemptions->min('redemption_date'),
                    'to_date' => $redemptions->max('redemption_date'),
                    'total_sales' => $totalSales,
                    'total_discount' => $totalDiscount,
                    'payable_amount' => $totalDiscount,
                    'redem_count' => count($redemptions),
                    'status' => 'pending'
                ]);

                foreach ($redemptions as $row) {
                    StoreSettlementItem::create([
                        'settlement_id' => $settlement->id,
                        'redemption_id' => $row->redemption_id,
                        'amount' => $row->discountOwner
                    ]);

                    LoyaltyRedemption::where('redemption_id', $row->redemption_id)->update([
                        'settlement_id' => $settlement->id
                    ]);
                }
            });
        }

        return response()->json([
            'message' => 'Settlement Generated'
        ]);
    }

    public function markPaid(Request $request, $id)
    {
        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        $validator = Validator::make(
            array_merge($request->all(), ['id' => $id]),
            [
                'id' => [
                    'required',
                    Rule::exists('store_settlement', 'id')
                        ->where(function ($query) use ($AgencyID) {
                            $query->where('AgencyID', $AgencyID)->where('status', 'pending');
                        }),
                ],
                'payment_mode' => 'required|string',
                'reference_no' => 'required|string',
                'remarks' => 'required|string',
            ],
            [
                'id.exists' => 'Settlement not found or already processed.',
            ]
        );
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

        $settlement = $this->settlementService->markPaid(
            $id,
            $request->user(),
            $request->payment_mode,
            $request->reference_no,
            $request->remarks
        );

        return response()->json($settlement);
    }
    public function partialPayment(Request $request, $id)
    {
        $settlement = $this->settlementService->partialPayment(
            $id,
            $request->amount,
            $request->payment_mode,
            $request->reference_no,
            $request->remarks
        );

        return response()->json([
            'status' => true,
            'message' => 'Payment Added Successfully',
            'data' => $settlement
        ]);
    }

    public function bulkPaid(Request $request)
    {
        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        $validator = Validator::make($request->all(), [
            'settlement_ids' => 'required|array|min:1',

            'settlement_ids.*' => [
                'required',
                Rule::exists('store_settlement', 'id')
                    ->where(function ($query) use ($AgencyID) {
                        $query->where('AgencyID', $AgencyID)
                            ->where('status', 'pending');
                    }),
            ],

            'payment_mode' => 'required|string',
            'reference_no' => 'required|string',
            'remarks' => 'required|string',
        ], [
            'settlement_ids.required' => 'Settlement ids are required.',
            'settlement_ids.array' => 'Settlement ids must be an array.',
            'settlement_ids.*.exists' => 'One or more settlements are invalid or already processed.',
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
        $settlement = $this->settlementService->bulkPaid(
            $request->settlement_ids,
            $request->user(),
            $request->payment_mode,
            $request->reference_no,
            $request->remarks
        );
        return response()->json($settlement);
    }
}
