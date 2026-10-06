<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Voucher\VoucherOrderResource;
use App\Services\Voucher\Gateways\VoucherPaymentGateway;
use App\Services\Voucher\VoucherPurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class VoucherCheckoutController extends Controller
{
    public function __construct(private VoucherPaymentGateway $gateway, private VoucherPurchaseService $purchase) {}

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
    public function purchase(Request $request)
    {
        $request->merge(['merchTxnId' => $request->merchant_txn_id]);
        $request->merge(['atomTxnId' => $request->atomTxnId]);
        $request->merge(['statusCode' => $request->statusCode]);
        $request->merge(['amount' => $request->amount]);
        $order = null;
        try {
            $order = $this->purchase->handleGatewayResult($this->gateway->parseCallback($request));
            return response()->json([
                'status'           => true,
                'order'            => $order,
            ]);
        } catch (Throwable $e) {
            Log::error('Voucher payment callback failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'           => false,
                'order'            => null,
                'message'            => $e->getMessage(),
            ]);
        }
    }
}
