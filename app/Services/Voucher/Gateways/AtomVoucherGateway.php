<?php

namespace App\Services\Voucher\Gateways;

use App\Models\User;
use App\Models\VoucherOrder;
use App\Models\VoucherPayment;
use Illuminate\Http\Request;
use LogicException;

class AtomVoucherGateway implements VoucherPaymentGateway
{
    public function initiate(VoucherOrder $order, VoucherPayment $payment, User $user): array
    {
        // TODO: call your existing NTT Data Pay (ATOM) code here.
        //  - merchTxnId = $payment->merchant_txn_id
        //  - amount     = $order->total_amount
        //  - return url = route('voucher.payment.callback')
        //  - generate the atomTokenId / encrypted request as you already do for other bookings

        return [
            'gateway'         => 'atom',
            'merchant_txn_id' => $payment->merchant_txn_id,
            'amount'          => (float) $order->total_amount,
            'return_url'      => null //route('voucher.payment.callback'),
            // 'atom_token_id' => $atomTokenId,
            // 'merch_id'      => config('services.atom.merchant_id'),
        ];
    }

    public function parseCallback(Request $request): array
    {
        $decoded = $request->all();
        // TODO: decrypt + verify the ATOM response with your existing code, then return:
        return [
            'merchant_txn_id' => $decoded['merchTxnId'],
            'gateway_txn_id'  => $decoded['atomTxnId'] ?? null,
            'status'          => $decoded['statusCode'] === 'OTS0000' ? 'success' : 'failed',
            'amount'          => (float) $decoded['amount'],
            'raw'             => $decoded,
        ];

        throw new LogicException('Wire ATOM response verification here before going live.');
    }
}
