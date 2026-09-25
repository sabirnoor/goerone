<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EarningController;
use App\Http\Controllers\API\LoyaltyController;
use App\Http\Controllers\API\MembershipController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\StoreController;
use App\Http\Controllers\API\VouchersController;
use App\Http\Controllers\Auth\GoerOneAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\Payment\IndexController;
use App\Http\Controllers\Payment\ScanPayController;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login']);
Route::get('/', function (Request $request) {
    return print_r(UserType());
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::prefix("customer")->group(function () {
        Route::post('create', [AuthController::class, 'createcustomer']);
        Route::post('fetch', [AuthController::class, 'fetchcustomer']);
        Route::post('importcustomer', [AuthController::class, 'importcustomer']);
        Route::post('updateprofile', [AuthController::class, 'updateprofile']);
        Route::post('devices-verify', [AuthController::class, 'devicesVerify']);
    });

    Route::prefix("store")->group(function () {
        Route::post('create', [StoreController::class, 'createStore']);
        Route::post('fetch', [StoreController::class, 'fetchStore']);
        Route::post('fetch-store-categories', [StoreController::class, 'fetchStoreCategories']);
        Route::post('search', [StoreController::class, 'search']);
        Route::post('remove-image/{imageId}', [StoreController::class, 'removeStoreImage']);
    });

    Route::prefix("loyalty")->group(function () {
        Route::post('program', [LoyaltyController::class, 'getLoyaltyProgram']);
        Route::post('loyalty_card', [LoyaltyController::class, 'loyaltyCard']);
        Route::post('loyalty_card_unassign', [LoyaltyController::class, 'loyaltyCardUnAssign']);
        Route::post('user_card', [LoyaltyController::class, 'loyaltyUserCard']);
        Route::post('reward', [LoyaltyController::class, 'loyaltyReward']);
        Route::post('rewardparent/{parent_id}', [LoyaltyController::class, 'loyaltyRewardPrent']);
        //Route::post('redeem', [LoyaltyController::class, 'redeemReward']);
        Route::post('redeem', [LoyaltyController::class, 'redeemReward']);
        Route::post('redemptionhistory', [LoyaltyController::class, 'getRedemptionHistoryAll']);
        Route::post('redemptionreport', [LoyaltyController::class, 'getRedemptionReport']);
        Route::post('redemptionreportcustomer', [LoyaltyController::class, 'getRedemptionReportCustomer']);
        Route::post('redemptionreportreward', [LoyaltyController::class, 'getRedemptionReportReward']);
        Route::post('earningbycustomer', [EarningController::class, 'earningbycustomer']);
        Route::post('addcard', [LoyaltyController::class, 'addcard']);
        Route::post('addreward', [LoyaltyController::class, 'addreward']);
        Route::post('assign', [LoyaltyController::class, 'assigncard']);

        Route::post('city-service', [LoyaltyController::class, 'cityService']);

        //Route::post('validateusercard', [LoyaltyController::class, 'validateusercard']);
        Route::post('membership', [MembershipController::class, 'membershipList']);
        Route::post('membership/{program_id}/details', [MembershipController::class, 'membershipDetails']);
        Route::post('savemembership', [MembershipController::class, 'addMembership']);
        Route::post('membership/{program_id}/togglestatus', [MembershipController::class, 'toggleMembershipStatus']);

        Route::post('store-reward-vouchers', [LoyaltyController::class, 'storeRewardVouchers']);
    });

    Route::prefix("payment")->group(function () {
        Route::any('/scan-qrcode', [ScanPayController::class, 'scanQrcode'])->name('scan-qrcode');
        Route::any('/scan-pay', [ScanPayController::class, 'scanPay'])->name('scan-pay');
        Route::any('/payment-history', [ScanPayController::class, 'getPaymentHistory'])->name('payment-history');
        Route::any('/user-balance', [ScanPayController::class, 'getUserBalance'])->name('user-balance');
    });
    Route::prefix("voucher")->group(function () {
        Route::any('/', [VouchersController::class, 'index']);
        Route::any('/add', [VouchersController::class, 'addnew']);
        Route::any('/voucher-list', [VouchersController::class, 'voucherList']);
        Route::any('/getvoucher', [VouchersController::class, 'voucherListAPI']);
    });
    Route::prefix("products")->group(function () {
        Route::any('/fetch', [ProductController::class, 'fetchproducts'])->name('products.fetch');
        Route::post('/create', [ProductController::class, 'createproducts'])->name('products.create');
        Route::post('/deleteproducts/{id}', [ProductController::class, 'deleteproducts'])->name('products.deleteproducts');
        Route::post('/fetchproductinfo/{id}', [ProductController::class, 'fetchproductinfo'])->name('products.info');
        Route::post('/fetchproductdetails/{id}', [ProductController::class, 'fetchproductdetails'])->name('products.details');
        Route::post('/createproductdetails/{id}', [ProductController::class, 'createproductdetails'])->name('products.detailscreate');
        Route::get('/fetchimages/{id}', [ProductController::class, 'fetchimages'])->name('products.fetchimages');
        Route::post('/saveimages/{id}', [ProductController::class, 'uploadimages'])->name('products.uploadimages');
        Route::get('/fetchaddresses/{id}', [ProductController::class, 'fetchaddresses'])->name('products.fetchaddresses');
        Route::post('/saveaddresses/{id}', [ProductController::class, 'saveaddresses'])->name('products.saveaddresses');
        Route::post('/getproduct', [ProductController::class, 'getproductAPI'])->name('products.getproduct');
        Route::post('/orders_place', [ProductController::class, 'placeOrder'])->name('products.orders_place');
        Route::post('/orders_history', [ProductController::class, 'ordersHistory'])->name('products.orders_history');
        Route::post('/create_order_status', [ProductController::class, 'createLogs'])->name('products.create_order_status');
        Route::post('/orders_status_log/{order_id}', [ProductController::class, 'ordersStatusLog'])->name('products.orders_status_log');
    });

    Route::prefix("tickets")->group(function () {
        Route::post('/services', fn() => Service::where('status', 1)->get());
    });

    Route::prefix('sms')->group(function () {
        Route::post('/sendotp', [MessageController::class, 'sendOTP']);
        Route::post('/verifyotp', [MessageController::class, 'verifyOtp']);
    });

    Route::post('payment/verify', [IndexController::class, 'verifyPayment']);
    Route::post('/initiate_payment', [IndexController::class, 'initiate_payment']);
    Route::any('/payment/response', [IndexController::class, 'atomresponse']);
});

Route::middleware('apiKey')->group(function () {
    Route::prefix('GoerOne')->group(function () {
        Route::post('login', [GoerOneAuthController::class, 'login']);
        Route::post('register', [GoerOneAuthController::class, 'register']);
        Route::post('otp-verify', [GoerOneAuthController::class, 'otpverify']);
    });
});
Route::post('/country', [DashboardController::class, 'country'])->name('country');
Route::post('/states', [DashboardController::class, 'states'])->name('states');
Route::post('/cities', [DashboardController::class, 'cities'])->name('cities');
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
