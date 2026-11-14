<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Stepwise self-registration for a store (draft -> pending), called with the
 * 'onboarding' Sanctum token that StoreAuthController hands out.
 *
 * Routes (inside auth:sanctum, deliberately NOT behind EnsureStoreApproved):
 *   Route::get('/store/onboarding', [StoreOnboardingController::class, 'show']);
 *   Route::post('/store/onboarding/step/{step}', [StoreOnboardingController::class, 'saveStep'])->whereNumber('step');
 *   Route::post('/store/onboarding/remove-image/{imageId}', [StoreOnboardingController::class, 'removeImage']);
 *   Route::post('/store/onboarding/submit', [StoreOnboardingController::class, 'submit']);
 *
 * Steps: 1 basics | 2 location | 3 timings & offerings | 4 photos & GST (optional) | 5 bank (optional)
 *
 * Every step only writes its own whitelisted columns, so a self-registering store can never
 * send platform_fee, is_active, registration_status, relation_manager, enquiry/payment_enabled...
 */
class StoreOnboardingController extends Controller
{
    /** Statuses in which the owner may still edit */
    private const EDITABLE = [Store::STATUS_DRAFT, Store::STATUS_REJECTED];

    public function show(Request $request)
    {
        $store = $this->currentStore($request);
        if (!$store instanceof Store) {
            return $store;
        }

        return $this->ok('Success', $store);
    }

    public function saveStep(Request $request, int $step)
    {
        $store = $this->currentStore($request);
        if (!$store instanceof Store) {
            return $store;
        }

        if (!in_array($store->registration_status, self::EDITABLE, true)) {
            return $this->fail(
                $store->registration_status === Store::STATUS_PENDING
                    ? 'Your application is under review and cannot be edited right now.'
                    : 'This store is already registered.',
                403
            );
        }

        if ($step < 1 || $step > Store::ONBOARDING_STEPS) {
            return $this->fail('Invalid step.', 422);
        }

        // Steps are filled in order; earlier steps can always be revisited.
        if ($step > (int) $store->onboarding_step + 1) {
            return $this->fail('Please complete the previous step first.', 422);
        }

        // Step 5 (bank) is optional and can be skipped.
        if ($step === 5 && $request->boolean('skip')) {
            $this->advance($store, 5);
            return $this->ok('Skipped bank details.', $store->fresh());
        }

        if ($step === 5 && $request->filled('bank_ifsc')) {
            $request->merge(['bank_ifsc' => strtoupper(trim($request->bank_ifsc))]);
        }
        if ($step === 4 && $request->filled('gst_number')) {
            $request->merge(['gst_number' => strtoupper(trim($request->gst_number))]);
        }

        $validator = Validator::make($request->all(), $this->rules($step, $store), [
            'latitude.required' => 'Please pick your store location on the map.',
            'longitude.required' => 'Please pick your store location on the map.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => ['success' => false, 'httpStatus' => 2001],
                'message' => implode(',', array_unique($validator->errors()->all())),
                'error' => $validator->messages(),
            ]);
        }

        try {
            DB::transaction(function () use ($request, $store, $step) {
                $data = $this->dataFor($step, $request, $store);
                $data['onboarding_step'] = max((int) $store->onboarding_step, $step);
                $data['updated_at'] = now();
                Store::where('id', $store->id)->update($data);

                if ($step === 4) {
                    $this->saveGallery($request, $store);
                }
            });
        } catch (\Throwable $th) {
            return $this->fail($th->getMessage(), 500);
        }

        return $this->ok('Saved.', $store->fresh());
    }

    public function removeImage(Request $request, $imageId)
    {
        $store = $this->currentStore($request);
        if (!$store instanceof Store) {
            return $store;
        }
        if (!in_array($store->registration_status, self::EDITABLE, true)) {
            return $this->fail('Your application cannot be edited right now.', 403);
        }

        $image = StoreImage::where('id', $imageId)->where('store_id', $store->id)->first();
        if (!$image) {
            return $this->fail('Image not found.', 404);
        }
        $image->delete();

        return $this->ok('Image removed.', $store->fresh());
    }

    public function submit(Request $request)
    {
        $store = $this->currentStore($request);
        if (!$store instanceof Store) {
            return $store;
        }

        if (!in_array($store->registration_status, self::EDITABLE, true)) {
            return $this->fail('Your application has already been submitted.', 409);
        }

        // Check the data itself (not just onboarding_step): steps 1-3 carry the mandatory fields.
        $missing = $store->missingRegistrationFields();
        if ($missing) {
            return $this->fail('Please complete: ' . implode(', ', $missing) . '.', 422);
        }

        $from = $store->registration_status;
        DB::transaction(function () use ($store, $from) {
            Store::where('id', $store->id)->update([
                'registration_status' => Store::STATUS_PENDING,
                'submitted_at' => now(),
                // the previous rejection stays in store_status_logs
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'updated_at' => now(),
            ]);

            DB::table('store_status_logs')->insert([
                'store_id' => $store->id,
                'from_status' => $from,
                'to_status' => Store::STATUS_PENDING,
                'changed_by' => null,
                'reason' => $from === Store::STATUS_REJECTED ? 'Re-applied after rejection' : 'Submitted for review',
                'created_at' => now(),
            ]);
        });

        // TODO: notify admin / relationship team (and the store) via your WhatsApp notification
        // service, e.g. a "store_submitted" template.

        return $this->ok('Application submitted. We will review it shortly.', $store->fresh());
    }

    // ------------------------------------------------------------------------------------------

    private function rules(int $step, Store $store): array
    {
        switch ($step) {
            case 1:
                return [
                    'store_name' => 'required|string|max:255',
                    'contact_name' => 'required|string|max:150',
                    'vendor_category' => ['required', 'string', Rule::in(StoreController::VENDOR_CATEGORIES)],
                    'product_types' => 'nullable|string',
                    'email' => ['nullable', 'string', 'lowercase', 'email', 'max:255', Rule::unique('stores', 'email')->ignore($store->id)],
                ];

            case 2:
                return [
                    'address' => 'required|string|max:500',
                    'country' => 'nullable',
                    'state' => 'required',
                    'city' => 'required',
                    'pincode' => 'required|string|min:4|max:10',
                    'latitude' => [
                        'required', 'numeric', 'between:-90,90',
                        function ($attribute, $value, $fail) {
                            if ((float) $value == 0.0 && (float) request('longitude') == 0.0) {
                                $fail('Please pick your store location on the map.');
                            }
                        },
                    ],
                    'longitude' => 'required|numeric|between:-180,180',
                    'placeId' => 'nullable|string|max:255',
                ];

            case 3:
                return [
                    // JSON {day: {is_open, open_time, close_time}} - at least one open day
                    'store_timings' => [
                        'required', 'string',
                        function ($attribute, $value, $fail) {
                            $decoded = json_decode($value, true);
                            if (!is_array($decoded)) {
                                return $fail('Store timings are invalid.');
                            }
                            $hasOpenDay = collect($decoded)->contains(fn ($d) => !empty($d['is_open']));
                            if (!$hasOpenDay) {
                                $fail('Please select at least one open day.');
                            }
                        },
                    ],
                    'food_type' => ['nullable', Rule::in(['veg', 'non_veg', 'both'])],
                    'is_halal' => 'nullable|boolean',
                    'alcohol_allowed' => 'nullable|boolean',
                    'description' => 'nullable|string|max:1000',
                    'alt_mobile' => 'nullable|digits_between:9,12',
                    'website_url' => 'nullable|url|max:255',
                ];

            case 4:
                return [
                    'store_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
                    'store_images' => 'nullable|array|max:5',
                    'store_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:4096',
                    'gst_number' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
                ];

            case 5:
                // Same rules as the bank block in StoreController::createStore().
                $existingCheque = $store->bank_cancelled_cheque;
                return [
                    'bank_account_number' => ['required', 'digits_between:9,18', 'confirmed'],
                    'bank_account_number_confirmation' => ['required'],
                    'bank_account_holder_name' => ['required', 'string', 'max:150'],
                    'bank_ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                    'bank_name' => ['required', 'string', 'max:150'],
                    'bank_branch' => ['nullable', 'string', 'max:150'],
                    'bank_account_type' => ['required', Rule::in(Store::BANK_ACCOUNT_TYPES)],
                    'bank_cancelled_cheque' => [
                        Rule::requiredIf(empty($existingCheque)),
                        'nullable', 'file', 'mimes:jpeg,png,jpg,webp,pdf', 'max:4096',
                    ],
                ];
        }

        return [];
    }

    /** The only columns each step is allowed to write. */
    private function dataFor(int $step, Request $request, Store $store): array
    {
        switch ($step) {
            case 1:
                return [
                    'store_name' => trim($request->store_name),
                    'contact_name' => trim($request->contact_name),
                    'vendor_category' => $request->vendor_category,
                    'product_types' => $request->filled('product_types') ? $request->product_types : null,
                    'email' => $request->filled('email') ? strtolower(trim($request->email)) : null,
                ];

            case 2:
                return [
                    'address' => trim($request->address),
                    'country' => $request->country,
                    'state' => $request->state,
                    'city' => $request->city,
                    'pincode' => trim($request->pincode),
                    'lat' => $request->filled('latitude') ? $request->latitude : '',
                    'lon' => $request->filled('longitude') ? $request->longitude : '',
                    'placeId' => $request->filled('placeId') ? $request->placeId : '',
                ];

            case 3:
                return [
                    'store_timings' => $request->store_timings,
                    'food_type' => $request->filled('food_type') ? $request->food_type : null,
                    'is_halal' => $request->boolean('is_halal'),
                    'alcohol_allowed' => $request->boolean('alcohol_allowed'),
                    'description' => $request->filled('description') ? $request->description : null,
                    'alt_mobile' => $request->filled('alt_mobile') ? $request->alt_mobile : null,
                    'website_url' => $request->filled('website_url') ? $request->website_url : null,
                ];

            case 4:
                $data = ['gst_number' => $request->filled('gst_number') ? $request->gst_number : null];
                if ($request->hasFile('store_logo')) {
                    $image = $request->file('store_logo');
                    $name = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                    $image->move(public_path('uploads/store'), $name);
                    $data['store_logo'] = config('app.url') . '/uploads/store/' . $name;
                }
                return $data;

            case 5:
                $data = [
                    'bank_account_number' => $request->bank_account_number,
                    'bank_account_holder_name' => trim($request->bank_account_holder_name),
                    'bank_ifsc' => $request->bank_ifsc,
                    'bank_name' => trim($request->bank_name),
                    'bank_branch' => $request->filled('bank_branch') ? trim($request->bank_branch) : null,
                    'bank_account_type' => $request->bank_account_type,
                ];
                if ($request->hasFile('bank_cancelled_cheque')) {
                    $cheque = $request->file('bank_cancelled_cheque');
                    // extension() is guessed from the contents, not the client-supplied name
                    $name = 'cheque_' . time() . '_' . uniqid() . '.' . $cheque->extension();
                    $cheque->move(public_path('uploads/store/cheques'), $name);
                    $data['bank_cancelled_cheque'] = config('app.url') . '/uploads/store/cheques/' . $name;
                }
                return $data;
        }

        return [];
    }

    /** Gallery: up to 5 images in total, new uploads are appended. */
    private function saveGallery(Request $request, Store $store): void
    {
        if (!$request->hasFile('store_images')) {
            return;
        }

        $existingCount = $store->images()->count();
        $incoming = collect($request->file('store_images'))->take(max(0, 5 - $existingCount));

        foreach ($incoming as $index => $file) {
            $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/store/gallery'), $name);

            StoreImage::create([
                'store_id' => $store->id,
                'image_path' => config('app.url') . '/uploads/store/gallery/' . $name,
                'sort_order' => $existingCount + $index,
            ]);
        }
    }

    private function advance(Store $store, int $step): void
    {
        Store::where('id', $store->id)->update([
            'onboarding_step' => max((int) $store->onboarding_step, $step),
            'updated_at' => now(),
        ]);
    }

    /** The logged-in Store, or a 403 response when the token belongs to someone else. */
    private function currentStore(Request $request)
    {
        $store = $request->user();
        if (!$store instanceof Store) {
            return $this->fail('Only a store can use this endpoint.', 403);
        }
        return $store;
    }

    private function ok(string $message, Store $store)
    {
        $store->load('images');

        return response()->json([
            'status' => ['success' => true, 'httpStatus' => 200],
            'message' => $message,
            'store' => $store,
            'registration_status' => $store->registration_status,
            'onboarding_step' => (int) $store->onboarding_step,
            'rejection_reason' => $store->registration_status === Store::STATUS_REJECTED
                ? $store->rejection_reason
                : null,
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