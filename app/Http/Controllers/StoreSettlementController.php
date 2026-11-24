<?php

namespace App\Http\Controllers;

use App\Models\StoreSettlements;
use App\Services\StoreSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StoreSettlementController extends Controller
{
    protected $settlementService;

    public function __construct(StoreSettlementService $settlementService)
    {
        $this->settlementService = $settlementService;
    }

    private function agencyId($user)
    {
        return ($user->UserType == 1) ? $user->id : $user->AgencyID;
    }

    private function validationError($validator)
    {
        return response()->json([
            'status' => [
                'success' => false,
                'httpStatus' => 422,
            ],
            'message' => implode(',', $validator->errors()->all()),
            'error' => $validator->messages(),
        ]);
    }

    /** settlement must belong to the agency and still have an outstanding balance */
    private function openSettlementRule($AgencyID)
    {
        return Rule::exists('store_settlement', 'id')
            ->where(function ($query) use ($AgencyID) {
                $query->where('AgencyID', $AgencyID)->whereIn('status', ['pending', 'partial']);
            });
    }

    public function index(Request $request)
    {
        // scope by agency only (same rule as show / pay); settlements carry the STORE's UserSysId as
        // customer_id, so filtering on the logged-in user's UserSysId hid every generated settlement
        $query = StoreSettlements::with('store:id,store_name,email,phone,type,contact_name')
            ->where('AgencyID', $this->agencyId($request->user()));
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->store_id);
        }
        if ($request->filled('keyword')) {
            $kw = '%' . addcslashes(trim($request->keyword), '\\%_') . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('settlement_no', 'like', $kw)
                    ->orWhereHas('store', fn($s) => $s->where('store_name', 'like', $kw));
            });
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        return $query->orderBy('id', 'DESC')->paginate($this->perPage($request, 50));
    }

    /** Outstanding totals for the admin list (same scoping as index) */
    public function summary(Request $request)
    {
        $base = StoreSettlements::where('AgencyID', $this->agencyId($request->user()));

        return response()->json($this->summaryFor($base));
    }

    /** Filters shared by the due-transactions endpoints; returns the array, or a validation response */
    private function dueFilters(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:PENDING,FAILED,SUCCESS,NONE',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        return $request->only(['status', 'from', 'to', 'keyword']);
    }

    /**
     * Admin: redemptions that are due for settlement but not settled yet, with their reward_wallet_temp
     * status (PENDING / FAILED / SUCCESS / NONE = no temp row). Optional store_id.
     */
    public function dueTransactions(Request $request)
    {
        $filters = $this->dueFilters($request);
        if (!is_array($filters)) {
            return $filters;
        }

        return response()->json($this->settlementService->dueTransactions(
            $this->agencyId($request->user()),
            $request->filled('store_id') ? (int) $request->store_id : null,
            $filters,
            $this->perPage($request, 25)
        ));
    }

    /* ---------------- Store panel (read only) ---------------- */

    private function storeScope(Request $request)
    {
        $user = $request->user();
        return StoreSettlements::where('store_id', $user->id)->where('AgencyID', $user->AgencyID);
    }

    public function storeIndex(Request $request)
    {
        $query = $this->storeScope($request);
        $summary = $this->summaryFor($this->storeScope($request));

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        return response()->json([
            'data' => $query->orderBy('id', 'DESC')->paginate($this->perPage($request, 20)),
            'summary' => $summary,
        ]);
    }

    public function storeShow(Request $request, $id)
    {
        return $this->storeScope($request)->with(['items.redemption', 'payments'])->findOrFail($id);
    }

    /** Store panel (read only): the store's own due transactions with their approval status */
    public function storeDueTransactions(Request $request)
    {
        $user = $request->user();
        abort_if(!$user || empty($user->id), 403);

        $filters = $this->dueFilters($request);
        if (!is_array($filters)) {
            return $filters;
        }

        return response()->json($this->settlementService->dueTransactions(
            (int) $user->AgencyID,
            (int) $user->id,
            $filters,
            $this->perPage($request, 20)
        ));
    }

    private function perPage(Request $request, int $default): int
    {
        return max(1, min((int) $request->query('per_page', $default), 100));
    }

    private function summaryFor($base): array
    {
        $open = (clone $base)->whereIn('status', ['pending', 'partial']);
        $balance = DB::raw('payable_amount - paid_amount');

        return [
            // GoerOne still has to pay the store
            'to_store' => StoreSettlementService::dec((clone $open)->where('direction', 'store_receives')->sum($balance)),
            // the store still has to pay GoerOne
            'from_store' => StoreSettlementService::dec((clone $open)->where('direction', 'store_pays')->sum($balance)),
            'open_count' => (clone $open)->count(),
            'paid_count' => (clone $base)->where('status', 'paid')->count(),
        ];
    }

    public function show(Request $request, $id)
    {
        $query = StoreSettlements::with([
            'store',
            'items.redemption',
            'payments'
        ]);

        // scope to the caller's agency (previously any id could be read)
        if ($request->user()) {
            $query->where('AgencyID', $this->agencyId($request->user()));
        }

        return $query->findOrFail($id);
    }

    /**
     * Generate settlements for eligible redemptions.
     * Optional: store_id (single store), preview=1 (calculate only, nothing written).
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        $agencyId = null;

        if ($user) {
            if ($user->UserType != 1) {
                return response()->json([
                    'status' => ['success' => false, 'httpStatus' => 403],
                    'message' => 'Only admin can generate settlements.',
                ], 403);
            }
            $agencyId = $user->id;
        }

        $validator = Validator::make($request->all(), [
            'store_id' => 'nullable|integer|required_with:from,to,ignore_due',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'ignore_due' => 'nullable|boolean',
        ], [
            'store_id.required_with' => 'Select a store when using a date range or ignoring due days.',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        // ignoring the due days is a testing tool: blocked on production unless explicitly enabled
        $ignoreDue = $request->boolean('ignore_due');
        if ($ignoreDue && app()->environment('production') && !config('settlement.allow_test_override')) {
            return response()->json([
                'status' => ['success' => false, 'httpStatus' => 403],
                'message' => 'Ignoring due days is disabled on production.',
            ], 403);
        }

        $summary = $this->settlementService->generateAll(
            $request->filled('store_id') ? (int) $request->store_id : null,
            $request->boolean('preview'),
            $agencyId,
            [
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'ignore_due' => $ignoreDue,
            ]
        );

        return response()->json([
            'message' => $summary['preview'] ? 'Settlement Preview' : 'Settlement Generated',
            'data' => $summary,
        ]);
    }

    public function markPaid(Request $request, $id)
    {
        $AgencyID = $this->agencyId($request->user());
        $validator = Validator::make(
            array_merge($request->all(), ['id' => $id]),
            [
                'id' => ['required', $this->openSettlementRule($AgencyID)],
                'payment_mode' => 'required|string',
                'reference_no' => 'required|string',
                'remarks' => 'required|string',
            ],
            [
                'id.exists' => 'Settlement not found or already processed.',
            ]
        );
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $result = $this->settlementService->markPaid(
            (int) $id,
            $request->user(),
            $request->payment_mode,
            $request->reference_no,
            $request->remarks
        );

        return response()->json($result);
    }

    public function partialPayment(Request $request, $id)
    {
        $AgencyID = $this->agencyId($request->user());
        $validator = Validator::make(
            array_merge($request->all(), ['id' => $id]),
            [
                'id' => ['required', $this->openSettlementRule($AgencyID)],
                'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
                'payment_mode' => 'required|string',
                'reference_no' => 'required|string',
                'remarks' => 'required|string',
            ],
            [
                'id.exists' => 'Settlement not found or already processed.',
                'amount.regex' => 'Amount can have at most 2 decimal places.',
            ]
        );
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $result = $this->settlementService->partialPayment(
            (int) $id,
            $request->user(),
            $request->amount,
            $request->payment_mode,
            $request->reference_no,
            $request->remarks
        );

        if (empty($result['status']['success'])) {
            return response()->json($result);
        }

        // keep the response shape the screen already expects on success
        return response()->json([
            'status' => true,
            'message' => 'Payment Added Successfully',
            'data' => $result['data'],
        ]);
    }

    public function bulkPaid(Request $request)
    {
        $AgencyID = $this->agencyId($request->user());
        $validator = Validator::make($request->all(), [
            'settlement_ids' => 'required|array|min:1',
            'settlement_ids.*' => ['required', $this->openSettlementRule($AgencyID)],
            'payment_mode' => 'required|string',
            'reference_no' => 'required|string',
            'remarks' => 'required|string',
        ], [
            'settlement_ids.required' => 'Settlement ids are required.',
            'settlement_ids.array' => 'Settlement ids must be an array.',
            'settlement_ids.*.exists' => 'One or more settlements are invalid or already processed.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $result = $this->settlementService->bulkPaid(
            $request->settlement_ids,
            $request->user(),
            $request->payment_mode,
            $request->reference_no,
            $request->remarks
        );

        return response()->json($result);
    }
}