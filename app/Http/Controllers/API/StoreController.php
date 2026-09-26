<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use App\Models\LoyaltyUserCard;
use App\Models\LoyaltyReward;
use App\Models\Store;
use App\Models\StoreImage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Http\Controllers\API\AuthController;
use App\Helpers\Helper;
use App\Models\Staticcities;
use App\Models\User;

class StoreController extends Controller
{


    public $APP_URL;
    public $APP_NAME;
    public $API_URL;
    protected $otpService;

    const VENDOR_CATEGORIES = [
        'Dining',
        'Hotels',
        'Shopping',
        'Health',
        'Beauty',
        'Fitness',
        'Travel',
        'Entertainment',
    ];

    public function __construct()
    {
        $this->APP_NAME = env('APP_NAME');
        $this->APP_URL = env('APP_URL');
        $this->API_URL = env('API_URL');
    }

    /**
     * Remove a single gallery image from a store (multi-image upload, up to 5).
     * Route suggestion: POST /store/remove-image/{imageId}
     */
    public function removeStoreImage(Request $request, $imageId)
    {
        try {
            $user = $request->user();
            $image = StoreImage::with('store')->find($imageId);

            if (!$image || !$image->store) {
                return response()->json([
                    'status' => ['success' => false, 'httpStatus' => 404],
                    'message' => 'Image not found.',
                ]);
            }

            $isOwner = $user->UserType == 1
                ? $image->store->AgencyID == $user->id
                : $image->store->UserSysId == $user->id;

            if (!$isOwner) {
                return response()->json([
                    'status' => ['success' => false, 'httpStatus' => 403],
                    'message' => 'You are not allowed to remove this image.',
                ]);
            }

            $image->delete();

            return response()->json([
                'status' => ['success' => true, 'httpStatus' => 200],
                'message' => 'Image removed successfully.',
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => ['success' => false, 'httpStatus' => 500],
                'message' => $th->getMessage(),
            ]);
        }
    }

    public function fetchStore(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $keyword = (isset($request->keyword) && !empty($request->keyword)) ? $request->keyword : '';
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $user = $request->user();
                $result = Store::select('stores.*', 'stores.lat as latitude', 'stores.lon as longitude', 'mst_country.name as name', 'static_cities.fullRegionName')
                    ->with('images')
                    ->leftjoin('static_cities', 'stores.city', '=', 'static_cities.id')
                    ->leftjoin('mst_country', 'stores.country', '=', 'mst_country.id')
                    ->where(function ($query) use ($user) {
                        if ($user->UserType == 1) {
                            $query->where('AgencyID', $user->id);
                        } else {
                            $query->where('UserSysId', $user->id);
                        }
                    })->where(function ($query) use ($keyword) {
                        if ($keyword !== "null" && !empty(trim($keyword))) {
                            $query->where('stores.store_name', "like", "%" . $keyword . "%");
                            $query->orWhere('stores.address', "like", "%" . $keyword . "%");
                            $query->orWhere('stores.email', "like", "%" . $keyword . "%");
                            $query->orWhere('stores.phone', "like", "%" . $keyword . "%");
                            $query->orWhere('stores.contact_name', "like", "%" . $keyword . "%");
                        }
                    })->orderBy('created_at', 'desc')->paginate($perPage);

                if ($user && $result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => $result,
                        'UserType' => UserType(),
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

    /**
     * Returns the list of vendor categories. Kept generic (no user/agency
     * scoping) since this is consumed by every user to populate
     * dropdowns/filters.
     */
    public function fetchStoreCategories(Request $request)
    {
        try {
            $categories = collect(self::VENDOR_CATEGORIES)->values();

            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Success',
                'data' => $categories,
            ]);
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

    public function createStore(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $storeId = isset($request->id) ? $request->id : "";
                $sharedRules = [
                    'store_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
                    'vendor_category' => 'nullable|string|max:255',
                    // (d) up to 5 gallery images, sent as store_images[] in the multipart form
                    'store_images' => 'nullable|array|max:5',
                    'store_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:4096',
                    // (a) JSON-encoded {day: {is_open, open_time, close_time}} string
                    'store_timings' => 'nullable|string',
                    // (b) JSON-encoded array of product type strings
                    'product_types' => 'nullable|string',
                    // (c) at least one of enquiry/payment must be enabled
                    'enquiry_enabled' => 'nullable|boolean',
                    'payment_enabled' => 'nullable|boolean',
                ];

                if (!empty($storeId)) {
                    $validator = Validator::make($request->all(), array_merge($sharedRules, [
                        'store_name' => 'required|string|max:255',
                        'phone' => ['required', 'digits_between:9,12', Rule::unique('stores', 'phone')->ignore($storeId)],
                        // 'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('stores', 'email')->ignore($storeId)],
                    ]));
                } else {

                    $validator = Validator::make($request->all(), array_merge($sharedRules, [
                        'store_name' => 'required|string|max:255',
                        'phone' => ['required', 'digits_between:9,12', Rule::unique('stores', 'phone')->ignore($storeId, 'id')],
                        'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('stores', 'email')->ignore($storeId)],
                    ]));
                }

                if (!$request->boolean('enquiry_enabled') && !$request->boolean('payment_enabled')) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 2001,
                        ],
                        'message' => 'Please enable Enquiry, Payment, or both.',
                    ]);
                }

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
                            'httpStatus' => 2001,
                        ],
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                }
                $cleanDate = preg_replace('/GMT[\+\-]\d{4}.*$/', '', $request->event_date);
                $carbonDate = Carbon::parse($cleanDate);
                $formattedDate = $carbonDate->format('Y-m-d');
                $namesss = explode(' ', $request->contact_name);
                $userRegister = [
                    'agencyName' => $request->store_name,
                    'fname' => isset($namesss[0]) ? $namesss[0] : '',
                    'lname' => isset($namesss[1]) ? $namesss[1] : $namesss[0],
                    'UserType' => 2,
                    'countrycode'  => isset($request->country_code) ? $request->country_code : 91,
                    'mobile' => $request->phone,
                    'email' =>  $request->email,
                    'password' => !empty($request->password) ? $request->password : 'SGNHA@123229875hgsreg097',
                    'password_confirmation' => !empty($request->password) ? $request->password : 'SGNHA@123229875hgsreg097',

                ];

                // return response()->json([
                //     'status' => [
                //         'success' => false,
                //         'httpStatus' => 201,
                //     ],
                //     'message' => $request->all(),
                //     'formattedDate' => $formattedDate,
                // ]);
                $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
                $userCheck = User::where('AgencyID', $AgencyID)->where('email', $request->email)->where('mobile', $request->phone)->first();
                if (empty($storeId)) {
                    if ($userCheck) {
                        return response()->json([
                            'status' => [
                                'success' => false,
                                'httpStatus' => 201,
                            ],
                            'message' => 'The selected email ID and mobile number already exist. Please use a different email address and mobile number.',
                        ]);
                    }
                }
                $storeData = [
                    'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                    'store_name'   => $request->store_name,
                    'store_id'     => $request->store_id,
                    'settlement_day'     => $request->settlement_day ?? 7,
                    'email'        => $request->email,
                    'contact_name' => $request->contact_name,
                    'phone'       => $request->phone,
                    'alt_mobile'   => $request->alt_mobile,
                    'website_url'  => $request->website_url,
                    'description'  => $request->description,
                    'address'      => $request->address,
                    'country'      => $request->country,
                    'state'        => $request->state,
                    'city'         => $request->city,
                    'type'         => $request->type,
                    'pincode'         => $request->pincode,
                    'noOfPass'         => isset($request->noOfPass) ? (int)$request->noOfPass : 0,
                    'gatenumber'         => isset($request->gatenumber) ? $request->gatenumber : '',
                    'event_time'         => isset($request->event_time) ? $request->event_time : '',
                    'entry_time'         => isset($request->entry_time) ? $request->entry_time : '',
                    'rewardrequired'         => isset($request->rewardrequired) ? (int)$request->rewardrequired : 0,
                    'swipelimit'         => isset($request->swipelimit) ? (int)$request->swipelimit : 0,
                    'swipeinyear'         => isset($request->swipeinyear) ? (int)$request->swipeinyear : 0,
                    'monthlyswipe'         => isset($request->monthlyswipe) ? (int)$request->monthlyswipe : 0,
                    'OTPAllowed'         => isset($request->OTPAllowed) ? (int)$request->OTPAllowed : 0,
                    'vendortype'         => isset($request->vendortype) ? (int)$request->vendortype : 0,
                    'vendor_category'         => isset($request->vendor_category) ? $request->vendor_category : '',
                    'FaceRecognition'         => isset($request->FaceRecognition) ? (int)$request->FaceRecognition : 0,
                    'event_date'         => (isset($request->event_date) && !empty($request->event_date)) ? $formattedDate : now(),
                    'lat'         => isset($request->latitude) ? $request->latitude : '',
                    'lon'         => isset($request->longitude) ? $request->longitude : '',
                    'placeId'         => isset($request->placeId) ? $request->placeId : '',
                    // (a) weekly open/close schedule — unchecked days come through with is_open=false
                    'store_timings'   => $request->filled('store_timings') ? $request->store_timings : null,
                    // (b) product types the store deals in
                    'product_types'   => $request->filled('product_types') ? $request->product_types : null,
                    // (c) enquiry / payment / both
                    'enquiry_enabled' => $request->boolean('enquiry_enabled'),
                    'payment_enabled' => $request->boolean('payment_enabled'),
                    // 'dealtype'         => isset($request->dealtype) ? (int)$request->dealtype : 0,
                    // 'rewardtype'         => isset($request->rewardtype) ? (int)$request->rewardtype : 0,
                    // 'dealvalue'         => isset($request->dealvalue) ? (float)$request->dealvalue : 0,
                    // 'ownervalue'         => isset($request->ownervalue) ? (float)$request->ownervalue : 0,
                    // 'custvalue'         => isset($request->custvalue) ? (float)$request->custvalue : 0,
                    // 'ordervalue'         => isset($request->ordervalue) ? (float)$request->ordervalue : 0,
                    // 'rewardvalue'         => isset($request->rewardvalue) ? (float)$request->rewardvalue : 0,
                    // 'password'     => !empty($request->password) ? Hash::make($request->password) : Hash::make(Str::random(10)),
                    'updated_at'   => now(),
                ];
                $imagePath = null;
                if ($request->hasFile('store_logo')) {
                    $image = $request->file('store_logo');
                    $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                    $image->move(public_path('uploads/store'), $imageName);
                    $imagePath = 'uploads/store/' . $imageName;
                }
                if ($imagePath) {
                    $storeData['store_logo'] = $this->APP_URL . '/' . $imagePath;
                }
                if ($request->password) {
                    $storeData['password'] =  Hash::make($request->password);
                }
                $request->merge($userRegister);
                $authController = app(AuthController::class);
                $response = $authController->register($request);
                $data = $response->getData(true);
                $status = isset($data['status']) ? $data['status'] : 0;
                if ($userCheck) {
                    $UserData = isset($data['UserData']['id']) ? $data['UserData']['id'] : $userCheck->id;
                } else {
                    $UserData = isset($data['UserData']['id']) ? $data['UserData']['id'] : $request->user()->id;
                }

                if ($status != 1 && empty($storeId)) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 201,
                        ],
                        'message' => isset($data['message']) ? $data['message'] : '',
                    ]);
                }
                if ($storeId) {
                    unset($storeData['email']);
                    $storeData['UserSysId'] =  $UserData;
                    Store::where('id', $storeId)->update($storeData);
                    $store = Store::with('images')->find($storeId);
                    $message = "Store Updated Successfully!";
                } else {
                    $storeData['UserSysId'] =  $UserData;
                    $storeData['created_at'] = now();
                    $insertGetId = Store::insertGetId($storeData);
                    $store = Store::find($insertGetId);
                    $message = "Store Created Successfully!";
                }

                // (d) Gallery images — up to 5. New uploads are appended; existing
                // rows are left alone unless the frontend explicitly asks to remove one
                // (see removeStoreImage()).
                if ($store && $request->hasFile('store_images')) {
                    $existingCount = $store->images()->count();
                    $incoming = collect($request->file('store_images'))->take(max(0, 5 - $existingCount));

                    foreach ($incoming as $index => $galleryImage) {
                        $galleryImageName = time() . '_' . uniqid() . '.' . $galleryImage->getClientOriginalExtension();
                        $galleryImage->move(public_path('uploads/store/gallery'), $galleryImageName);

                        StoreImage::create([
                            'store_id' => $store->id,
                            'image_path' => $this->APP_URL . '/uploads/store/gallery/' . $galleryImageName,
                            'sort_order' => $existingCount + $index,
                        ]);
                    }

                    $store->load('images');
                }

                if ($store) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => $message,
                        'result' => $store,
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

   private const NO_SERVICE_MESSAGE = "Sorry, we don't currently provide our service in this area.";

/**
 * Single entry point for both the city-based "discover" feed and the
 * distance-based "nearby" feed. ...
 */
public function search(Request $request)
{
    try {
        $mode = $request->mode === 'nearby' ? 'nearby' : 'discover';

        $rules = [
            'mode'     => 'nullable|in:discover,nearby',
            'category' => 'nullable|string|max:191',
            'limit'    => 'nullable|integer|min:1|max:50',
        ];

        if ($mode === 'nearby') {
            $rules['lat']    = 'required|numeric|between:-90,90';
            $rules['lon']    = 'required|numeric|between:-180,180';
            $rules['radius'] = 'nullable|numeric|min:0.1|max:100';
            $rules['offset'] = 'nullable|integer|min:0';
        } else {
            $rules['city']   = 'required|string|max:191';
            $rules['cursor'] = 'nullable|integer';
            $rules['lat']    = 'nullable|numeric|between:-90,90';
            $rules['lon']    = 'nullable|numeric|between:-180,180';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $category = trim($request->category ?? '');
        $limit    = (int) ($request->limit ?? 15);

        return $mode === 'nearby'
            ? $this->searchNearby($request, $category, $limit)
            : $this->searchDiscover($request, $category, $limit);
    } catch (\Throwable $th) {
        return $this->jsonError($th->getMessage());
    }
}

/**
 * Discover stores by city. Uses cursor pagination instead of offset pagination.
 */
private function searchDiscover(Request $request, string $category, int $limit)
{
    try {
        $city   = trim($request->city);
        $cursor = $request->cursor;
        $lat = $request->filled('lat') ? (float) $request->lat : null;
        $lon = $request->filled('lon') ? (float) $request->lon : null;
        $user = $request->user();
        $AgencyID = $user->UserType == 1 ? $user->id : $user->AgencyID;

        $query = Store::query()
            ->select([
                'stores.id', 'stores.store_name', 'stores.store_logo',
                'stores.address', 'stores.description', 'stores.vendor_category',
                'stores.lat', 'stores.lon', 'stores.placeId', 'stores.city',
                'static_cities.cityName',
            ])
            ->join('static_cities', 'stores.city', '=', 'static_cities.id')
            ->where('stores.AgencyID', $AgencyID);

        $cityLike = '%' . strtolower($city) . '%';
        $query->where(function ($q) use ($city, $cityLike) {
            $q->whereRaw('LOWER(static_cities.cityName) = ?', [strtolower($city)])
                ->orWhereRaw('LOWER(stores.address) LIKE ?', [$cityLike]);
        });

        if ($category !== '') {
            $query->where('stores.vendor_category', $category);
        }

        // Optional distance calculation — display-only, doesn't affect sorting/pagination.
        if (
            $lat !== null && $lat !== '' &&
            $lon !== null && $lon !== '' &&
            strtolower(trim((string) $lat)) !== 'null' &&
            strtolower(trim((string) $lon)) !== 'null'
        ) {
            $query->selectRaw($this->haversineExpression(), [$lat, $lon, $lat]);
        }

        if (!empty($cursor)) {
            $query->where('stores.id', '<', (int) $cursor);
        }

        $rows = $query->orderByDesc('stores.id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        if ($hasMore) { $rows = $rows->take($limit); }

        $categories = collect(self::VENDOR_CATEGORIES)->values();

        if ($rows->isEmpty()) {
            return $this->jsonSuccess('No stores found in ' . $city . '.', [
                'items'      => [],
                'nextCursor' => null,
                'mode'       => 'discover',
                'categories' => $categories,
            ]);
        }

        $rewardMap = LoyaltyReward::getBestRewardsForStores($rows->pluck('id')->toArray(), $AgencyID);
        $items = $rows->map(fn($store) => $this->formatStore($store, true, $rewardMap))->values();

        return $this->jsonSuccess('Stores fetched successfully.', [
            'items'      => $items,
            'nextCursor' => $hasMore ? $rows->last()->id : null,
            'mode'       => 'discover',
            'categories' => $categories,
        ]);
    } catch (\Throwable $th) {
        return $this->jsonError($th->getMessage());
    }
}

/**
 * Nearby stores. Bounding box -> Haversine -> radius filter -> paginate.
 */
private function searchNearby(Request $request, string $category, int $limit)
{
    try {
        $lat = (float) $request->lat;
        $lon = (float) $request->lon;
        $radius = (float) ($request->radius ?? 50);
        $offset = (int) ($request->offset ?? 0);
        $user = $request->user();
        $AgencyID = $user->UserType == 1 ? $user->id : $user->AgencyID;

        $latDelta = $radius / 111.0;
        $cosLat = max(abs(cos(deg2rad($lat))), 0.000001);
        $lonDelta = $radius / (111.0 * $cosLat);
        $minLat = max(-90, $lat - $latDelta);
        $maxLat = min(90, $lat + $latDelta);
        $minLon = $lon - $lonDelta;
        $maxLon = $lon + $lonDelta;

        $query = Store::query()
            ->select([
                'id', 'store_name', 'store_logo', 'address', 'description',
                'vendor_category', 'lat', 'lon', 'placeId', 'city',
            ])
            ->whereNotNull('lat')
            ->whereNotNull('lon')
            ->where('AgencyID', $AgencyID)
            ->whereBetween('lat', [$minLat, $maxLat])
            ->whereBetween('lon', [$minLon, $maxLon]);

        if ($category !== '') {
            $query->where('vendor_category', $category);
        }

        $query->selectRaw($this->haversineExpression(), [$lat, $lon, $lat]);
        $query->having('distance_km', '<=', $radius);

        $rows = $query->orderBy('distance_km')->orderBy('id')
            ->offset($offset)->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) { $rows = $rows->take($limit); }

        $categories = collect(self::VENDOR_CATEGORIES)->values();

        // No stores at all in this radius (only meaningful on the first page).
        if ($rows->isEmpty() && $offset === 0) {
            return $this->jsonSuccess(self::NO_SERVICE_MESSAGE, [
                'items'      => [],
                'nextOffset' => null,
                'mode'       => 'nearby',
                'categories' => $categories,
            ]);
        }

        $rewardMap = LoyaltyReward::getBestRewardsForStores($rows->pluck('id')->toArray(), $AgencyID);
        $items = $rows->map(fn($store) => $this->formatStore($store, false, $rewardMap))->values();

        return $this->jsonSuccess('Nearby stores fetched successfully.', [
            'items'      => $items,
            'nextOffset' => $hasMore ? $offset + $limit : null,
            'mode'       => 'nearby',
            'categories' => $categories,
        ]);
    } catch (\Throwable $th) {
        return $this->jsonError($th->getMessage());
    }
}

/**
 * Shared Haversine SQL fragment (used by both discover's optional distance
 * calc and nearby's radius filter). Bindings order is always [lat, lon, lat].
 */
private function haversineExpression(string $latColumn = 'lat', string $lonColumn = 'lon'): string
{
    return "(6371 * acos(
        LEAST(1, GREATEST(-1,
            cos(radians(?)) * cos(radians({$latColumn})) * cos(radians({$lonColumn}) - radians(?))
            + sin(radians(?)) * sin(radians({$latColumn}))
        ))
    )) AS distance_km";
}

private function jsonSuccess(string $message, array $data, int $status = 200)
{
    return response()->json([
        'status'  => ['success' => true, 'httpStatus' => $status],
        'message' => $message,
        'data'    => $data,
    ]);
}

private function jsonError(string $message, int $status = 500)
{
    return response()->json([
        'status'  => ['success' => false, 'httpStatus' => $status],
        'message' => $message,
    ]);
}
    /**
     * Common store response formatter.
     */
    private function formatStore($store,bool $discover = false, $rewardMap = null): array {
        $address = $store->address;
    /*
    |--------------------------------------------------------------------------
    | Discover address fallback
    |--------------------------------------------------------------------------
    */
    if ($discover) {

        $trimmedAddress = trim((string) $address);
        if ($address === null || $trimmedAddress === '' || strtolower($trimmedAddress) === 'null') {
            $address = $store->cityName;
            }
        }

    /*
    |--------------------------------------------------------------------------
    | Best reward for this store (if any)
    |--------------------------------------------------------------------------
    */

    $reward = $rewardMap ? $rewardMap->get($store->id) : null;
    $discount = null;

    if ($reward) {
            $discount = [
                'rewardId'    => $reward->reward_id,
                'dealType'    => (int) $reward->dealtype,   // 0 = percentage, 1 = fixed amount
                'dealValue'   => (float) $reward->custvalue,
                'rewardType'  => (int) $reward->rewardtype,  // 0 = percentage, 1 = fixed amount
                'rewardValue' => (float) $reward->rewardvalue,
                'minOrderValue' => (float) $reward->ordervalue,
                'startDate'   => $reward->start_date,
                'endDate'     => $reward->end_date,
                'maxdiscountvalue'=>(float) $reward->maxdiscountvalue,
                'custvalue'=>(float) $reward->custvalue,
                'ownervalue'=>(float) $reward->ownervalue,
                'max_reward_value'=>(float) $reward->max_reward_value,
            ];
        }

        return [
            'id'          => $store->id,
            'name'        => $store->store_name,
            'logo'        => $store->store_logo,
            'address'     => $address,
            'description' => $store->description,
            'category'    => $store->vendor_category,
            'lat'         => $store->lat,
            'lon'         => $store->lon,
            'placeId'     => $store->placeId,
            'distanceKm' => isset($store->distance_km)
                && $store->distance_km !== null
                ? round((float) $store->distance_km, 2): null,
            'rating'      => null,
            'reviewCount' => 0,
            'discount'    => $discount,
        ];
    }
}
