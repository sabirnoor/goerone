<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Admin side of store self-registration.
 *
 * Routes (inside auth:sanctum, admin only - same UserType == 1 rule as toggleStatus):
 *   Route::post('/store/registration/approve', [StoreRegistrationController::class, 'approve']);
 *   Route::post('/store/registration/reject',  [StoreRegistrationController::class, 'reject']);
 *   Route::post('/store/registration/history', [StoreRegistrationController::class, 'history']);
 *
 * The Pending / Approved / Rejected / Incomplete tabs and their counts come from
 * StoreController::fetchStore (registration_status filter + status_counts).
 */
class StoreRegistrationController extends Controller
{
    /**
     * Approve a submitted store. Admin fills the commercial details here, then the store goes live:
     * registration_status = approved, is_active = 1, qr_handle generated, audit fields set.
     */
    public function approve(Request $request)
    {
        $admin = $this->adminOrFail($request);
        if (!$admin instanceof \App\Models\User) {
            return $admin;
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'platform_fee' => 'required|numeric|min:0|max:100',
            'platform_fee_payer' => ['required', Rule::in(['store', 'customer'])],
            'relation_manager' => 'nullable|integer|exists:users,id',
            'enquiry_enabled' => 'nullable|boolean',
            'payment_enabled' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        // Neither toggle sent => turn both on. Both explicitly off is not allowed (same rule as createStore).
        $enquiry = $request->has('enquiry_enabled') ? $request->boolean('enquiry_enabled') : true;
        $payment = $request->has('payment_enabled') ? $request->boolean('payment_enabled') : true;
        if (!$enquiry && !$payment) {
            return $this->fail('Please enable Enquiry, Payment, or both.', 422);
        }

        $store = Store::where('id', $request->id)->where('AgencyID', $admin->id)->first();
        if (!$store) {
            return $this->fail('Store not found.', 404);
        }

        if ($store->registration_status === Store::STATUS_APPROVED) {
            return $this->fail('This store is already approved.', 409);
        }
        // An incomplete (draft) store can be approved on the owner's behalf, e.g. admin spoke to the
        // owner and completed the details from Edit store.
        $from = $store->registration_status;
        $onBehalf = $from === Store::STATUS_DRAFT;

        // Same completeness rules for every status. Includes the map location, where 0,0 counts as missing.
        $missing = $store->missingRegistrationFields();
        if ($missing) {
            return $this->fail(
                ($onBehalf ? 'Complete these details first (Edit store): ' : 'This application is missing: ') . implode(', ', $missing) . '.',
                422
            );
        }

        DB::transaction(function () use ($request, $store, $admin, $from, $enquiry, $payment, $onBehalf) {
            $update = [
                'registration_status' => Store::STATUS_APPROVED,
                'is_active' => 1,
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'submitted_at' => $store->submitted_at ?? now(),
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'platform_fee' => (float) $request->platform_fee,
                'platform_fee_payer' => $request->platform_fee_payer,
                'relation_manager' => $request->filled('relation_manager') ? (int) $request->relation_manager : null,
                'enquiry_enabled' => $enquiry,
                'payment_enabled' => $payment,
                'updated_at' => now(),
            ];

            // Permanent QR handle: generated once, never overwritten (same rule as createStore).
            if (empty($store->qr_handle)) {
                $update['qr_handle'] = Store::makeQrHandle($store->id);
            }

            Store::where('id', $store->id)->update($update);

            $this->log(
                $store->id,
                $from,
                Store::STATUS_APPROVED,
                $admin->id,
                $onBehalf ? 'Approved by admin on behalf of the store (details completed by admin)' : 'Approved'
            );
        });

        // TODO: send the "your store is live" WhatsApp notification via your notification service
        // (wrap it in try/catch so a failed message never rolls back an approval).

        return $this->ok('Store approved and activated.', Store::with(['images', 'relationshipManager:id,name,mobile,countrycode'])->find($store->id));
    }

    /** Reject a submitted store. The reason is shown to the owner on their next login. */
    public function reject(Request $request)
    {
        $admin = $this->adminOrFail($request);
        if (!$admin instanceof User) {
            return $admin;
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'reason' => 'required|string|min:3|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $store = Store::where('id', $request->id)->where('AgencyID', $admin->id)->first();
        if (!$store) {
            return $this->fail('Store not found.', 404);
        }

        if ($store->registration_status !== Store::STATUS_PENDING) {
            return $this->fail('Only a submitted (pending) application can be rejected.', 409);
        }

        DB::transaction(function () use ($request, $store, $admin) {
            Store::where('id', $store->id)->update([
                'registration_status' => Store::STATUS_REJECTED,
                'is_active' => 0,
                'rejected_at' => now(),
                'rejected_by' => $admin->id,
                'rejection_reason' => trim($request->reason),
                'updated_at' => now(),
            ]);

            $this->log($store->id, $store->registration_status, Store::STATUS_REJECTED, $admin->id, trim($request->reason));
        });

        // TODO: send the rejection WhatsApp notification (with the reason) via your notification service.

        return $this->ok('Store application rejected.', Store::find($store->id));
    }

    /** Status trail for one store (submitted / rejected / re-applied / approved), newest first. */
    public function history(Request $request)
    {
        $admin = $this->adminOrFail($request);
        if (!$admin instanceof \App\Models\User) {
            return $admin;
        }

        $validator = Validator::make($request->all(), ['id' => 'required|integer']);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $store = Store::where('id', $request->id)->where('AgencyID', $admin->id)->first();
        if (!$store) {
            return $this->fail('Store not found.', 404);
        }

        $logs = DB::table('store_status_logs')
            ->leftJoin('users', 'users.id', '=', 'store_status_logs.changed_by')
            ->where('store_status_logs.store_id', $store->id)
            ->orderByDesc('store_status_logs.id')
            ->get([
                'store_status_logs.from_status',
                'store_status_logs.to_status',
                'store_status_logs.reason',
                'store_status_logs.created_at',
                'users.name as changed_by_name',
            ]);

        return response()->json([
            'status' => ['success' => true, 'httpStatus' => 200],
            'message' => 'Success',
            'data' => $logs,
        ]);
    }

    // ------------------------------------------------------------------------------------------

    private function log(int $storeId, ?string $from, string $to, ?int $by, ?string $reason): void
    {
        DB::table('store_status_logs')->insert([
            'store_id' => $storeId,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $by,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** Same rule as StoreController::toggleStatus - only the agency admin (UserType 1). */
    private function adminOrFail(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof \App\Models\User || $user->UserType != 1) {
            return $this->fail('Only an admin can review store registrations.', 403);
        }
        return $user;
    }

    private function ok(string $message, $store)
    {
        return response()->json([
            'status' => ['success' => true, 'httpStatus' => 200],
            'message' => $message,
            'result' => $store,
        ]);
    }

    private function fail(string $message, int $http)
    {
        return response()->json([
            'status' => ['success' => false, 'httpStatus' => $http],
            'message' => $message,
        ], $http);
    }
}