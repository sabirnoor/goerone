<?php

namespace App\Services\Voucher\Gateways;

use App\Models\User;
use App\Models\VoucherOrder;
use App\Models\VoucherPayment;
use Illuminate\Http\Request;

interface VoucherPaymentGateway
{
    /**
     * Start a payment. Return whatever the frontend needs to open the gateway
     * (redirect URL, token, form fields...).
     */
    public function initiate(VoucherOrder $order, VoucherPayment $payment, User $user): array;

    /**
     * Verify the gateway response (signature / decryption) and normalise it to:
     * [
     *   'merchant_txn_id' => string,
     *   'gateway_txn_id'  => string|null,
     *   'status'          => 'success'|'failed'|'pending',
     *   'amount'          => float,
     *   'raw'             => array,   // stored in voucher_payments.response_payload
     * ]
     * Must throw if verification fails. Never trust unverified request data.
     */
    public function parseCallback(Request $request): array;
}
