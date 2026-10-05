<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Voucher\VoucherOrderResource;
use App\Services\Voucher\VoucherPurchaseService;
use Illuminate\Http\Request;

class VoucherCheckoutController extends Controller
{
    public function __construct(private VoucherPurchaseService $purchase) {}

    public function checkout(Request $request)
    {
        $result = $this->purchase->checkout($request->user());

        return response()->json([
            'status'           => true,
            'payment_required' => $result['payment_required'],
            'order'            => new VoucherOrderResource($result['order']),
            'payment'          => $result['payment'], // gateway data for the frontend to open the payment page
        ]);
    }
}
