<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use App\Models\LoyaltyUserCard;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Services\RewardService;
use App\Helpers\Helper;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\Product_types;
use App\Models\ProductDetails;
use App\Models\Products;
use App\Models\Products_Images;
use App\Models\Products_PickupAddress;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\File;

class ProductController extends Controller
{


    public $APP_URL;
    public $APP_NAME;
    public $API_URL;
    protected $otpService;
    private $rewardService;
    public function __construct(RewardService $rewardService)
    {
        $this->APP_NAME = env('APP_NAME');
        $this->APP_URL = env('APP_URL');
        $this->API_URL = env('API_URL');
        $this->rewardService = $rewardService;
    }

    public function fetchproducts(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                // return response(Products_Images::get()->toArray());
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                // $user = $request->user();
                $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
                $result = Products::where('is_deleted', '0')->where('AgencyID', $AgencyID)->orderBy('created_at', 'desc')->paginate($perPage);
                $producttypelist = Product_types::OrderBy('order_by', 'ASC')->get();

                if ($result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => $result,
                        'producttypelist' => $producttypelist,
                        'UserType' => UserType(),
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 500,
                        ],
                        'message' => 'Oops something went wrong',
                        'producttypelist' => $producttypelist,
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

    public function createproducts(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $productID = isset($request->product_id) ? $request->product_id : "";
                // dd($request->all());
                $validator = Validator::make($request->all(), [
                    'product_name' => 'required|max:255',
                    'product_type' => 'required|max:255',
                    'total_qty' => 'required|max:255',
                    // 'sold_qty' => 'required|max:255',
                    'buying_price' => 'required|max:255',
                    'selling_price' => 'required|max:255',
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
                            'httpStatus' => 2001,
                        ],
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                }
                $ProductData = [
                    'AgencyID' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                    'UserSysId' => $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID,
                    'product_name'   => $request->product_name,
                    'product_type'     => $request->product_type,
                    'total_qty'        => $request->total_qty,
                    // 'sold_qty' => $request->sold_qty,
                    'buying_price'       => $request->buying_price,
                    'selling_price'   => $request->selling_price,
                    'updated_at'   => now(),
                ];
                if ($productID) {
                    Products::where('id', $productID)->update($ProductData);
                    $products = true;
                    $message = "products Updated Successfully!";
                } else {
                    $ProductData['created_at'] = now();
                    $ProductData['is_deleted'] = 0;
                    $insertGetId = Products::insertGetId($ProductData);
                    $products = Products::find($insertGetId);
                    $message = "products Created Successfully!";
                }
                if ($products) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => $message,
                        'result' => $products,
                        'productID' => $productID ? $productID : $insertGetId
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
    public function deleteproducts(Request $request, $id)
    {
        try {
            Products::where('id', $id)->update(['is_deleted' => '1']);
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Product Deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 200,
                ],
                'message' => 'Error deleting Product: ' . $e->getMessage(),
            ]);
        }
    }
    public function fetchproductinfo(Request $request, $id)
    {
        try {
            if ($request->isMethod('post')) {
                $result = Products::where('id', $id)->first();
                $producttypelist = Product_types::OrderBy('order_by', 'ASC')->get();
                if ($result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => $result,
                        'producttypelist' => $producttypelist
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
    public function fetchproductdetails(Request $request, $id)
    {
        try {
            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                // $user = $request->user();
                $result = ProductDetails::where('products_id', $id)->orderBy('order_by', 'asc')->paginate($perPage);


                if ($result) {
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

    public function createproductdetails(Request $request, $id)
    {
        try {
            if ($request->isMethod('post')) {
                $validator = Validator::make($request->all(), [
                    'products' => 'present|array',
                    'products.*.title' => 'required|max:255',
                    'products.*.description' => 'required|max:255',
                    'products.*.products_id' => 'required|max:255',
                    'deleted_ids' => 'sometimes|array',
                    'deleted_ids.*' => 'integer',
                ], [
                    'products.present' => 'Products field is required',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 2001,
                        ],
                        'message' => 'Validation failed',
                        'error' => $validator->messages(),
                    ]);
                }

                $processedCount = 0;
                $deletedCount = 0;
                $now = now();

                // Handle deletions first
                if (!empty($request->deleted_ids)) {
                    $deletedCount = ProductDetails::whereIn('id', $request->deleted_ids)->delete();
                }

                // Handle updates and inserts
                foreach ($request->products as $productData) {
                    $productdetID = $productData['id'] ?? null;

                    $productRecord = [
                        'title' => $productData['title'],
                        'order_by' => ($productData['order_by'] > 0) ? (int)$productData['order_by'] : 0,
                        'description' => $productData['description'],
                        'products_id' => $productData['products_id'],
                        'updated_at' => $now,
                    ];

                    if ($productdetID) {
                        // For update - use the specific ID
                        $updated = ProductDetails::where('id', $productdetID)->update($productRecord);
                        if ($updated) {
                            $processedCount++;
                        }
                    } else {
                        // For insert
                        $productRecord['created_at'] = $now;
                        $inserted = ProductDetails::create($productRecord);
                        if ($inserted) {
                            $processedCount++;
                        }
                    }
                }

                $totalProcessed = $processedCount + $deletedCount;

                if ($totalProcessed > 0) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => "Successfully processed {$processedCount} product details and deleted {$deletedCount} records",
                        'processed_count' => $processedCount,
                        'deleted_count' => $deletedCount,
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 500,
                        ],
                        'message' => 'No changes were made',
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

    public function fetchimages(Request $request, $id)
    {
        try {
            $result = Products_Images::where('products_id', $id)->get();
            if ($result) {
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
            // }
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

    public function uploadimages(Request $request, $id)
    {
        try {
            $AGENCYID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
            $productId = $id;

            $uploadPath = public_path('uploads/products/' . $productId);
            if (!File::exists($uploadPath)) {
                File::makeDirectory($uploadPath, 0755, true);
            }

            $cards = $request->input('cards', []);
            $savedImages = [];

            // First, reset all primary images if any new one is being set as primary
            $hasNewPrimary = false;
            foreach ($cards as $card) {
                $isPrimary = isset($card['is_primary']) && $card['is_primary'] == '1';
                if ($isPrimary) {
                    $hasNewPrimary = true;
                    break;
                }
            }

            if ($hasNewPrimary) {
                // Reset all existing images for this product to non-primary
                Products_Images::where('products_id', $productId)
                    ->update(['is_primary' => false]);
            }

            foreach ($cards as $index => $card) {
                $title = $card['title'] ?? null;
                $order_by = $card['order_by'] ?? 0;
                $is_primary = isset($card['is_primary']) ? ($card['is_primary'] == '1') : false;
                $cardId = $card['id'] ?? null;

                $imageFile = $request->file("cards.$index.image");
                $existingImageUrl = $card['existing_image_url'] ?? null;

                // Skip if no image data (both file and existing URL)
                if (!$imageFile && !$existingImageUrl) {
                    continue;
                }

                $uploadedUrl = $existingImageUrl;

                // Process new file upload
                if ($imageFile) {
                    $validator = Validator::make(
                        ['image' => $imageFile],
                        ['image' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048']
                    );

                    if ($validator->fails()) {
                        $allErrors = implode(', ', $validator->errors()->all());
                        return response()->json([
                            "status" => ["success" => false],
                            "message" => $allErrors,
                            "errors"  => $validator->errors(),
                        ], 422);
                    }

                    $filename = 'products_' . time() . '_' . uniqid() . '_' . $index . '.webp';
                    $filePath = $uploadPath . '/' . $filename;

                    Helper::resizeAndConvertToWebP($imageFile, $filePath, 800, 600);
                    $uploadedUrl = url("uploads/products/$productId/$filename");
                }

                // Prepare image data
                $imageData = [
                    'products_id' => $productId,
                    'title' => $title,
                    'images' => $uploadedUrl,
                    'order_by' => $order_by,
                    'is_primary' => $is_primary,
                    // 'agency_id' => $AGENCYID,
                ];

                if ($cardId) {
                    // Update existing image
                    $productImage = Products_Images::where('id', $cardId)
                        ->where('products_id', $productId)
                        ->first();

                    if ($productImage) {
                        $productImage->update($imageData);
                    } else {
                        // Create new if ID doesn't exist
                        $productImage = Products_Images::create($imageData);
                    }
                } else {
                    // Create new image
                    $productImage = Products_Images::create($imageData);
                }

                $savedImages[] = [
                    'id' => $productImage->id,
                    'title' => $productImage->title,
                    'images' => $productImage->images,
                    'order_by' => $productImage->order_by,
                    'is_primary' => $productImage->is_primary,
                ];
            }

            // Handle deletions with file cleanup
            if ($request->has('deleted_ids')) {
                $deletedIds = is_array($request->deleted_ids) ? $request->deleted_ids : [];

                // Get the images that will be deleted
                $imagesToDelete = Products_Images::whereIn('id', $deletedIds)
                    ->where('products_id', $productId)
                    ->get();

                // Check if we're deleting a primary image
                $deletingPrimary = Products_Images::whereIn('id', $deletedIds)
                    ->where('products_id', $productId)
                    ->where('is_primary', true)
                    ->exists();

                foreach ($imagesToDelete as $image) {
                    // Extract filename from URL and delete physical file
                    if ($image->images) {
                        try {
                            $parsedUrl = parse_url($image->images);
                            $path = $parsedUrl['path'] ?? '';
                            $filename = ltrim($path, '/');
                            $filename = basename($filename);

                            if ($filename) {
                                $filePath = $uploadPath . '/' . $filename;
                                if (File::exists($filePath)) {
                                    File::delete($filePath);
                                }
                            }
                        } catch (Exception $e) {
                            // \Log::error('Failed to delete image file: ' . $e->getMessage());
                        }
                    }
                }

                // Delete the database records
                Products_Images::whereIn('id', $deletedIds)
                    ->where('products_id', $productId)
                    ->delete();

                // If we deleted a primary image, set the first remaining image as primary
                if ($deletingPrimary) {
                    $firstRemaining = Products_Images::where('products_id', $productId)
                        ->orderBy('id')
                        ->first();

                    if ($firstRemaining) {
                        $firstRemaining->update(['is_primary' => true]);

                        // Update the saved images array with the new primary
                        foreach ($savedImages as &$savedImage) {
                            if ($savedImage['id'] == $firstRemaining->id) {
                                $savedImage['is_primary'] = true;
                            }
                        }
                    }
                }
            }

            // If no image is set as primary after all operations, set the first one as primary
            $hasPrimary = Products_Images::where('products_id', $productId)
                ->where('is_primary', true)
                ->exists();

            if (!$hasPrimary && count($savedImages) > 0) {
                $firstImage = Products_Images::where('products_id', $productId)
                    ->orderBy('id')
                    ->first();

                if ($firstImage) {
                    $firstImage->update(['is_primary' => true]);

                    // Update the saved images array with the new primary
                    foreach ($savedImages as &$savedImage) {
                        if ($savedImage['id'] == $firstImage->id) {
                            $savedImage['is_primary'] = true;
                        }
                    }
                }
            }

            return response()->json([
                "status" => ["success" => true],
                "message" => "Images updated successfully",
                "data" => $savedImages
            ]);
        } catch (Exception $e) {
            return response()->json([
                "status" => ["success" => false],
                "message" => $e->getMessage(),
            ], 500);
        }
    }
    public function fetchaddresses(Request $request, $id)
    {
        try {
            // $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
            $result = Products_PickupAddress::where('products_id', $id)->orderBy('created_at', 'desc')->get();

            if ($result) {
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
    public function saveaddresses(Request $request, $id)
    {
        try {
            $AGENCYID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
            $productId = $id;

            // Validate required fields
            $validator = Validator::make($request->all(), [
                'addresses' => 'required|array',
                'addresses.*.title' => 'required|string|max:255',
                'addresses.*.address' => 'required|string',
                'addresses.*.is_primary' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    "status" => ["success" => false],
                    "message" => "Validation failed",
                    "errors" => $validator->errors()
                ], 422);
            }

            $addresses = $request->input('addresses', []);
            $savedAddresses = [];

            // Use transaction for data consistency
            DB::beginTransaction();

            try {
                // First, reset all primary addresses if any new one is being set as primary
                $hasNewPrimary = collect($addresses)->contains('is_primary', true);
                if ($hasNewPrimary) {
                    Products_PickupAddress::where('products_id', $productId)
                        ->update(['is_primary' => false]);
                }

                foreach ($addresses as $addressData) {
                    $title = $addressData['title'] ?? null;
                    $address = $addressData['address'] ?? null;
                    $is_primary = $addressData['is_primary'] ?? false;
                    $latitude = $addressData['latitude'] ?? false;
                    $longitude = $addressData['longitude'] ?? false;
                    $addressId = $addressData['id'] ?? null;

                    $addressRecord = [
                        'products_id' => $productId,
                        'title' => $title,
                        'address' => $address,
                        'is_primary' => $is_primary,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        // 'agency_id' => $AGENCYID,
                    ];

                    if ($addressId) {
                        // Update existing address
                        $existingAddress = Products_PickupAddress::where('id', $addressId)
                            ->where('products_id', $productId)
                            ->first();

                        if ($existingAddress) {
                            $existingAddress->update($addressRecord);
                            $savedAddresses[] = $existingAddress->fresh();
                        }
                    } else {
                        // Create new address
                        $newAddress = Products_PickupAddress::create($addressRecord);
                        $savedAddresses[] = $newAddress;
                    }
                }

                // Handle deletions
                if ($request->has('deleted_ids')) {
                    $deletedIds = is_array($request->deleted_ids) ? $request->deleted_ids : [];

                    if (!empty($deletedIds)) {
                        // Check if we're deleting a primary address
                        $deletingPrimary = Products_PickupAddress::whereIn('id', $deletedIds)
                            ->where('is_primary', true)
                            ->exists();

                        // Delete the addresses
                        Products_PickupAddress::whereIn('id', $deletedIds)
                            ->where('products_id', $productId)
                            ->delete();

                        // If we deleted a primary address, set the first remaining address as primary
                        if ($deletingPrimary) {
                            $firstRemaining = Products_PickupAddress::where('products_id', $productId)
                                ->orderBy('id')
                                ->first();

                            if ($firstRemaining) {
                                $firstRemaining->update(['is_primary' => true]);
                            }
                        }
                    }
                }

                // Ensure at least one address is primary
                $hasPrimary = Products_PickupAddress::where('products_id', $productId)
                    ->where('is_primary', true)
                    ->exists();

                if (!$hasPrimary) {
                    $firstAddress = Products_PickupAddress::where('products_id', $productId)
                        ->orderBy('id')
                        ->first();

                    if ($firstAddress) {
                        $firstAddress->update(['is_primary' => true]);
                    }
                }

                DB::commit();

                return response()->json([
                    "status" => ["success" => true],
                    "message" => "Addresses updated successfully",
                    "data" => $savedAddresses
                ]);
            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            return response()->json([
                "status" => ["success" => false],
                "message" => $e->getMessage(),
            ], 500);
        }
    }

    public function getproductAPI(Request $request)
    {
        $post = $request->all();
        if ($request->isMethod('post')) {
            try {

                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $result = Products::getProductsAPI($request->user(), $perPage, $post);
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


    public function placeOrder(Request $request)
    {
        $request->validate([]);

        $validator = Validator::make($request->all(), [
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty'        => 'required|numeric|min:1',
            'address'        => 'required|string|max:255',

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
        }
        $user = $request->user();
        $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
        $RewardSummary = $this->rewardService->getRewardSummary($user->id, $AgencyID);
        $total_rewardearn = isset($RewardSummary['total_rewardearn']) ? round($RewardSummary['total_rewardearn'], 2) : 0;
        return DB::transaction(function () use ($request, $total_rewardearn) {

            $user = auth()->user();
            // Generate Order Number
            $orderNumber = "ORD-" . time() . "-" . rand(100, 999);
            $subtotal = 0;
            $itemsData = [];

            // Calculate totals + verify stock
            foreach ($request->items as $item) {
                $product = Products::lockForUpdate()->find($item['product_id']);
                if ($product->total_qty - $product->sold_qty < $item['qty']) {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 400,
                        ],
                        'message' => "Stock not available for {$product->product_name}"
                    ]);
                }
                $lineTotal = $product->selling_price * $item['qty'];
                $subtotal += $lineTotal;
                $itemsData[] = [
                    'product_id'   => $product->id,
                    'product_name' => $product->product_name,
                    'qty'          => $item['qty'],
                    'price'        => $product->selling_price,
                    'total'        => $lineTotal
                ];
            }
            $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
            if ($total_rewardearn < $subtotal) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 400,
                    ],
                    'message' => "Insufficient GTcoin balance"
                ]);
            }

            $RewardRequest = [
                "points" => ceil($subtotal),
                "description" =>  !empty($request->notes) ? $request->notes : 'Order placed',
                'AgencyID' => $AgencyID,
                'UserSysId' =>  $request->user()->id,
                "payer_id" => $user->id,
                "payee_id" => $AgencyID,
                "RewardMode" => "Order",
                "ReferenceNo" => $orderNumber,
                'PlanType' => 6,
            ];
            $redemption = $this->rewardService->transferPoints($RewardRequest);
            $success = isset($redemption['success']) ? $redemption['success'] : 0;
            $message = isset($redemption['message']) ? $redemption['message'] : '';
            if ($success != 1) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 2002,
                    ],
                    'message' => $message,
                ]);
            }
            // Create Order
            $order = Order::create([
                'order_number'  => $orderNumber,
                'AgencyID'      => $AgencyID,
                'UserSysId'     => $user->id,
                'customer_id'     => $user->id,
                'subtotal'      => $subtotal,
                'discount'      => 0,
                'total_amount'  => $subtotal,
                'notes'  => $request->notes ?? '',
                'address'  => $request->address ?? '',
                'payment_status' => 1, // paid
                'order_status'   => 1  // confirmed
            ]);

            // Insert items + update stock
            foreach ($itemsData as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'total' => $item['total']
                ]);
                // Deduct stock
                Products::where('id', $item['product_id'])->increment('sold_qty', $item['qty']);
            }
            $order->updateOrderStatus(
                1,
                'Order placed',
                $user->id // logged-in user updating order
            );

            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Order placed successfully.',
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
        });
    }

    public function ordersHistory(Request $request)
    {
        $post = $request->all();
        if ($request->isMethod('post')) {
            try {

                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $result = Order::ordersHistoryAPI($request->user(), $perPage, $post);
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
    public function ordersStatusLog(Request $request, $order_id)
    {
        $post = $request->all();
        if ($request->isMethod('post')) {
            try {
                $result = OrderStatusLog::ordersStatusLogAPI($request->user(), $order_id);
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
    public function createLogs(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $productID = isset($request->product_id) ? $request->product_id : "";
                // dd($request->all());
                $validator = Validator::make($request->all(), [
                    'message' => 'required|max:255',
                    'order_id' => 'required|exists:orders,id',
                    'status' => 'required',
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
                            'httpStatus' => 2001,
                        ],
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                }
                OrderStatusLog::create([
                    'status' => $request->status,
                    'order_id' => $request->order_id,
                    'message' => $request->message,
                    'updated_by' => $request->user()->id
                ]);
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'message' => 'Created Successfully',
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
