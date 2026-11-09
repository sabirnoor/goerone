<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\Users;
use App\Models\WalletModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Services\TwilioService;
use App\Services\AgentBnplService;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;
use App\Services\EmailService;
use App\Models\AtomAES;
use App\Models\mst_payments_setting;
use App\Services\StoreSettlementService;

class WebhookController extends Controller
{

    private $VERIFY_TOKEN = 'ozilconnect_X3xQydrVeevUP7Zs2MeIjc9P2NlngnCH98FTU2YqboYOmQsXyzUTxMYK3oQC';
    public $API_URL;
    public $APP_URL;
    public $APP_NAME;
    protected $twilio;
    protected $bnplService;
    protected $emailService;
    protected $settlementService;
    public function __construct(
        TwilioService $twilio,
        AgentBnplService $bnplService,
        EmailService $emailService,
        StoreSettlementService $settlementService
    ) {
        $this->API_URL = env('API_URL');
        $this->APP_URL = env('APP_URL');
        $this->APP_NAME = env('APP_NAME');
        $this->twilio = $twilio;
        $this->bnplService = $bnplService;
        $this->emailService = $emailService;
        $this->settlementService = $settlementService;
    }

    public function deletetoken(Request $request)
    {

        $dddddd = DB::table('personal_access_tokens')
            ->whereIn('tokenable_id', [16749, 16686, 16684, 16683, 16680, 16575, 16573])
            ->where('tokenable_type', User::class)
            ->delete();
        pr($dddddd);
        die('d');
    }

    public function secureserver(Request $request)
    {
        $currentDate = date('Y-m-d H:i:s');
        // Step 1: Get the webhook payload
        $secret = env('RAZORPAY_WEBHOOK_SECRET');

        $payload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');

        $verified = false;
        if ($secret && $signature) {
            $expectedSignature = hash_hmac('sha256', $payload, $secret);
            $verified = hash_equals($expectedSignature, $signature);
        }

        if (!$verified) {
            Storage::disk('public')->put('logs/Webhook/' . $currentDate . '_response.json', json_encode(["Invalid Razorpay webhook signature."]));
            Log::warning('Razorpay Webhook signature verification failed.');
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $payloadData = $request->all();
        Storage::disk('public')->put('logs/Webhook/' . $currentDate . '_response.json', json_encode($payloadData));
        // Log::info('Razorpay Webhook Received:', $payloadData);

        // Handle the event types
        switch ($event) {
            case 'payment.captured':
                // Handle payment captured
                Storage::disk('public')->put('logs/Webhook/' . $currentDate . '_captured.json', json_encode($event));
                break;
            case 'payment.failed':
                // Handle payment failed
                Storage::disk('public')->put('logs/Webhook/' . $currentDate . '_failed.json', json_encode($event));
                break;
            case 'order.paid':
                Storage::disk('public')->put('logs/Webhook/' . $currentDate . '_paid.json', json_encode($event));
                // Handle order paid
                break;
            default:
                Storage::disk('public')->put('logs/Webhook/' . $currentDate . '_else.json', json_encode($event));
        }

        return response()->json(['status' => 'success']);
    }
    public function SetDataCashfree($payloadData)
    {
        $cashFeeUpdate = [
            'key' => '',
            'furl' => 'https://ozilconnect.com/payment/payment-success',
            'hash' => '',
            'mode' => $payloadData['data']['payment']['payment_group'] ?? '',
            'surl' => 'https://ozilconnect.com/payment/payment-success',
            'udf1' => $payloadData['data']['order']['order_tags']['udf1'] ?? '',
            'udf2' => $payloadData['data']['order']['order_tags']['udf2'] ?? '',
            'udf3' => $payloadData['data']['order']['order_tags']['udf3'] ?? '',
            'udf4' => $payloadData['data']['order']['order_tags']['udf4'] ?? '',
            'udf5' => $payloadData['data']['order']['order_tags']['udf5'] ?? '',
            'udf6' => $payloadData['data']['order']['order_tags']['udf6'] ?? '',
            'udf7' => $payloadData['data']['order']['order_tags']['udf7'] ?? '',
            'udf8' => $payloadData['data']['order']['order_tags']['udf8'] ?? '',
            'udf9' => $payloadData['data']['order']['order_tags']['udf9'] ?? '',
            'email' => $payloadData['data']['customer_details']['customer_email'] ?? '',
            'error' => ($payloadData['data']['payment']['payment_status'] == 'SUCCESS') ? 'Transaction is successful.' : $payloadData['data']['payment']['payment_status'],
            'phone' => $payloadData['data']['customer_details']['customer_phone'] ?? '',
            'txnid' => $payloadData['data']['order']['order_id'] ?? '',
            'udf10' => $payloadData['data']['order']['order_tags']['udf10'] ?? '',
            'amount' => $payloadData['data']['payment']['payment_amount'] ?? 0,
            'status' => ($payloadData['data']['payment']['payment_status'] == 'SUCCESS') ? 'success' : $payloadData['data']['payment']['payment_status'],
            'upi_va' => 'NA',
            'PG_TYPE' => 'NA',
            'addedon' =>  $payloadData['data']['payment']['payment_time'],
            'cardnum' => 'NA',
            'bankcode' => 'NA',
            'auth_code' => null,
            'bank_name' => 'NA',
            'card_type' => 'NA',
            'easepayid' => (string)$payloadData['data']['payment']['cf_payment_id'] ?? '',
            'firstname' => $payloadData['data']['customer_details']['customer_name'] ?? '',
            'productinfo' => $payloadData['data']['order']['order_tags']['productinfo'] ?? '',
            'service_tax' =>  $payloadData['data']['charges_details']['service_tax'] ?? 0,
            'auth_ref_num' => 'NA',
            'bank_ref_num' => $payloadData['data']['payment']['bank_reference'] ?? '',
            'cardCategory' => 'NA',
            'issuing_bank' => 'NA',
            'name_on_card' => 'NA',
            'discount_code' => 'NA',
            'error_Message' => ($payloadData['data']['error_details']['error_description']) ?? $payloadData['data']['payment']['payment_status'],
            'merchant_logo' => 'NA',
            'payment_source' => 'cashfree',
            'service_charge' => $payloadData['data']['charges_details']['service_charge'] ?? 0,
            'unmappedstatus' => 'NA',
            'discount_amount' => $payloadData['data']['charges_details']['service_charge_discount'] ?? 0,
            'net_amount_debit' => $payloadData['data']['payment']['payment_amount'] ?? 0,
            'payment_category' => 'DEFAULT',
            'settlement_amount' => $payloadData['data']['charges_details']['settlement_amount'] ?? 0,
            'cancellation_reason' => 'NA',
            'cash_back_percentage' => '0',
            'deduction_percentage' => '0'
        ];
        return $cashFeeUpdate;
    }
    public function SetDataAtom($payloadData, $merchId)
    {
        $atomResponse = $payloadData; // Your Atom response array

        $payInstrument = $atomResponse['payInstrument'];

        $merchDetails = $payInstrument['merchDetails'];
        $payDetails = $payInstrument['payDetails'];
        $payMode = $payInstrument['payModeSpecificData'];
        $extras = $payInstrument['extras'];
        $custDetails = $payInstrument['custDetails'];
        $responseDetails = $payInstrument['responseDetails'];

        $atomData = [
            'key' => $merchId,

            'furl' => null,

            'hash' => $payDetails['signature'] ?? '',

            'mode' => $payMode['subChannel'][0] ?? null,

            'surl' => null,

            'udf1' => $extras['udf1'] ?? null,
            'udf2' => $extras['udf2'] ?? null,
            'udf3' => $extras['udf3'] ?? null,
            'udf4' => $extras['udf4'] ?? null,
            'udf5' => $extras['udf5'] ?? null,
            'udf6' => $extras['udf6'] ?? null,
            'udf7' => $extras['udf7'] ?? null,
            'udf8' => $extras['udf8'] ?: null,
            'udf9' => $extras['udf9'] ?: null,

            'email' => $custDetails['custEmail'] ?? null,

            'error' => $responseDetails['description'] ?? null,

            'phone' => $custDetails['custMobile'] ?? null,

            'txnid' => $merchDetails['merchTxnId'] ?? null,

            'udf10' => $extras['udf10'] ?: null,

            'amount' => (string) ($payDetails['amount'] ?? 0),

            'status' => (
                ($responseDetails['statusCode'] ?? '') === 'OTS0000'
            ) ? 'success' : 'failure',

            'upi_va' => 'NA',

            'PG_TYPE' => 'NA',

            'addedon' => $payDetails['txnInitDate'] ?? null,

            'cardnum' => 'NA',

            'bankcode' => $payMode['bankDetails']['otsBankId'] ?? null,

            'auth_code' => null,

            'bank_name' => $payMode['bankDetails']['otsBankName'] ?? null,

            'card_type' => 'NA',

            'easepayid' => (string) $payDetails['atomTxnId'] ?? null,

            'firstname' => null,

            'productinfo' => $extras['udf7'] === 'recharge'
                ? 'Wallet Recharge'
                : null,

            'service_tax' => (string) (
                $payDetails['surchargeAmount'] ?? 0
            ),

            'auth_ref_num' => 'NA',

            'bank_ref_num' => $payMode['bankDetails']['bankTxnId'] ?? null,

            'cardCategory' => 'NA',

            'issuing_bank' => $payMode['bankDetails']['otsBankName'] ?? null,

            'name_on_card' => 'NA',

            'discount_code' => 'NA',

            'error_Message' => $responseDetails['description'] ?? null,

            'merchant_logo' => 'NA',

            'payment_source' => 'Atom',

            'service_charge' => (string) (
                $payDetails['surchargeAmount'] ?? 0
            ),

            'unmappedstatus' => 'NA',

            'discount_amount' => '0.0',

            'net_amount_debit' => (string) (
                $payDetails['totalAmount'] ?? 0
            ),

            'payment_category' => 'DEFAULT',

            'settlement_amount' => (string) (
                ($payDetails['totalAmount'] ?? 0)
                - ($payDetails['surchargeAmount'] ?? 0)
            ),

            'cancellation_reason' => 'NA',

            'cash_back_percentage' => '0.0',

            'deduction_percentage' => '0.0',
        ];
        return $atomData;
    }
    public function secureservereasebuzz(Request $request)
    {
        $currentDate = \Carbon\Carbon::now()->format('Y-m-d H:i:s.u');; //date('Y-m-d H:i:s');
        try {
            //$post = json_decode('{"data":{"order":{"order_id":"TXN1794030931835","order_amount":1000,"order_currency":"INR","order_tags":{"productinfo":"Store Purchase","udf1":"TXN1794030931835","udf10":null,"udf2":"1","udf3":"GoerOne","udf4":"154","udf5":"42","udf6":"INR","udf7":"749eeb0f5028097cab78efda2678b857aa03712ce2ca0d66417b2db1f7a7d2f31ad2a9dd43dece01b6e950bd63392036fbce227bd3fed7b39a458d877e0055beca9ee8438f271f48d912e406635015865d0f416c251e18f613666e5e5f6cf304a8a3b6ce55d249901d06e6827e9efd1aae4ae1618acdd2140d48c61dd7d9ca77","udf8":null,"udf9":null},"order_note":null},"payment":{"cf_payment_id":"1472471426228898816","payment_status":"SUCCESS","payment_amount":1000,"payment_currency":"INR","payment_message":"Simulated response message","payment_time":"2026-11-07T11:25:45+05:30","bank_reference":"1472471426228898816","auth_id":null,"payment_method":{"netbanking":{"channel":null,"netbanking_bank_code":"3044","netbanking_bank_name":"State Bank Of India"}},"payment_group":"net_banking","international_payment":null,"payment_surcharge":{"payment_surcharge_service_charge":0,"payment_surcharge_service_tax":0}},"customer_details":{"customer_name":"Jiten Kumar","customer_id":"154","customer_email":"singh.jiten25@gmail.com","customer_phone":"9654353576"},"payment_gateway_details":{"gateway_name":"CASHFREE","gateway_order_id":"1472471392343149568","gateway_payment_id":"1472471426228898816","gateway_status_code":null,"gateway_order_reference_id":"null","gateway_settlement":"CASHFREE","gateway_reference_name":null},"payment_offers":null,"terminal_details":null},"event_time":"2026-11-07T11:25:53+05:30","type":"PAYMENT_SUCCESS_WEBHOOK","pgsource":"2"}', 1);
            $post = $request->all();
            $pgsource = isset($post['pgsource']) ? $post['pgsource'] : '4';
            $type = isset($post['type']) ? $post['type'] : '';

            Storage::disk('public')->put('logs/Webhook/easebuzz/' . $currentDate . '_response.json', json_encode($post));
            // pr($post);
            // die;
            if ($pgsource == '2') {
                $payloadData = self::SetDataCashfree($post);
            } elseif ($pgsource == '5') {
                $encData = $post;
                $merchId = $encData['merchId'];
                $data = $encData['encData'] ?? '';
                $CheckSetting = mst_payments_setting::where('KeyID', $merchId)->where('isdefault', 1)->first();
                $resAESKey = $CheckSetting->resAESKey ?? '';
                // change decryption key below for production
                $decData = AtomAES::ATOMdecrypt($data, $resAESKey, $resAESKey);
                $jsonData = json_decode($decData, true);
                $payloadData = $this->SetDataAtom($jsonData, $merchId);
            } else {
                $payloadData = $post;
            }
            if (empty($payloadData)) {
                return response()->json(['status' => 'null response']);
            }

            $AgencyID = isset($payloadData['udf5']) ? $payloadData['udf5'] : 0;
            $customer_id = isset($payloadData['udf4']) ? $payloadData['udf4'] : 0;
            $easepayid = isset($payloadData['easepayid']) ? $payloadData['easepayid'] : '';
            $mode = isset($payloadData['mode']) ? $payloadData['mode'] : '';
            $udf3 = isset($payloadData['udf3']) ? $payloadData['udf3'] : '';
            $udf1 = $BookingID = isset($payloadData['udf1']) ? $payloadData['udf1'] : '';
            $udf2 = isset($payloadData['udf2']) ? $payloadData['udf2'] : '';
            $udf7 = isset($payloadData['udf7']) ? $payloadData['udf7'] : '';
            $udf6 = isset($payloadData['udf6']) ? $payloadData['udf6'] : '';
            $status = isset($payloadData['status']) ? $payloadData['status'] : '';
            $txnid = isset($payloadData['txnid']) ? $payloadData['txnid'] : '';

            Storage::disk('public')->put('logs/Webhook/easebuzz/' . $udf1 . '_' . $currentDate . '_response.json', json_encode($payloadData));

            $transactionData = [
                'agency_id' => isset($payloadData['udf5']) ? $payloadData['udf5'] : 0,
                'customer_id' => isset($payloadData['udf4']) ? $payloadData['udf4'] : 0,
                'udf1' => isset($payloadData['udf1']) ? $payloadData['udf1'] : '',
                'udf2' => isset($payloadData['udf2']) ? $payloadData['udf2'] : 0,
                'udf3' => isset($payloadData['udf3']) ? $payloadData['udf3'] : null,
                'udf6' => isset($payloadData['udf6']) ? $payloadData['udf6'] : null,
                'udf7' => isset($payloadData['udf7']) ? $payloadData['udf7'] : null,
                'udf8' => isset($payloadData['udf8']) ? $payloadData['udf8'] : null,
                'udf9' => isset($payloadData['udf9']) ? $payloadData['udf9'] : null,
                'email' => isset($payloadData['email']) ? $payloadData['email'] : '',
                'error' => isset($payloadData['error']) ? $payloadData['error'] : '',
                'phone' => isset($payloadData['phone']) ? $payloadData['phone'] : '',
                'txnid' => isset($payloadData['txnid']) ? $payloadData['txnid'] : '',
                'udf10' => isset($payloadData['udf10']) ? $payloadData['udf10'] : null,
                'amount' => isset($payloadData['amount']) ? number_format((float) $payloadData['amount'], 2, '.', '') : 0,
                'status' => isset($payloadData['status']) ? $payloadData['status'] : '',
                'addedon' => isset($payloadData['addedon']) ? $payloadData['addedon'] : '',
                'easepayid' => isset($payloadData['easepayid']) ? $payloadData['easepayid'] : '',
                'firstname' => isset($payloadData['firstname']) ? $payloadData['firstname'] : '',
                'productinfo' => isset($payloadData['productinfo']) ? $payloadData['productinfo'] : '',
                'service_tax' => isset($payloadData['service_tax']) ? number_format((float) $payloadData['service_tax'], 2, '.', '') : 0,
                'auth_ref_num' => isset($payloadData['auth_ref_num']) ? $payloadData['auth_ref_num'] : 0,
                'bank_ref_num' => isset($payloadData['bank_ref_num']) ? $payloadData['bank_ref_num'] : '',
                'error_message' => isset($payloadData['error_message']) ? $payloadData['error_message'] : '',
                'payment_source' => isset($payloadData['payment_source']) ? $payloadData['payment_source'] : '',
                'service_charge' => isset($payloadData['service_charge']) ? number_format((float) $payloadData['service_charge'], 2, '.', '') : 0,
                'unmappedstatus' => isset($payloadData['unmappedstatus']) ? $payloadData['unmappedstatus'] : '',
                'discount_amount' => isset($payloadData['discount_amount']) ? number_format((float) $payloadData['discount_amount'], 2, '.', '') : 0,
                'settlement_amount' => isset($payloadData['settlement_amount']) ? number_format((float) $payloadData['settlement_amount'], 2, '.', '') : 0,
                'cancellation_reason' => isset($payloadData['cancellation_reason']) ? $payloadData['cancellation_reason'] : '',
                'cash_back_percentage' => isset($payloadData['cash_back_percentage']) ? number_format((float) $payloadData['cash_back_percentage'], 2, '.', '') : 0,
                'deduction_percentage' => isset($payloadData['deduction_percentage']) ? number_format((float) $payloadData['deduction_percentage'], 2, '.', '') : 0,
                'created_at' => $currentDate,
                'updated_at' => $currentDate
            ];
            $existsuccess = PaymentTransaction::where('agency_id', $AgencyID)->where('status', 'success')->where('txnid', $txnid)->exists();
            //$transaction = PaymentTransaction::insertGetId($transactionData);
            if (!$existsuccess) {
                $transaction = PaymentTransaction::insertGetId($transactionData);
            }

            if ($status === 'success' && !$existsuccess) {
                $Walletstatus = 0;
                $response = $topUpResponse = $bookingProcess = [];
                $walletTransactions = WalletModel::from('wallet as w')->where('w.AgencyID', $AgencyID)
                    ->where('w.customer_id', $customer_id)
                    ->where('w.RefrenceNo', $easepayid)
                    ->where('w.PType', 'CR')
                    ->whereNull('w.CreditSystemID')
                    ->where('w.IsCreditPayment', false)
                    ->where('w.is_profit', false)
                    ->first();
                if (empty($walletTransactions)) {
                    $walletInsert = [
                        'AgencyID' => $AgencyID,
                        'UserSysId' =>  $customer_id,
                        "customer_id" =>  $customer_id,
                        "amount" => isset($payloadData['amount']) ? (float)$payloadData['amount'] : 0,
                        "PaymentMode" => $mode . '-' . $udf3,
                        "RefrenceNo" => $easepayid,
                        "Notes" => "Wallet Recharge Webhook",
                        'PlanType' => !empty($udf2) ? (int)$udf2 : 0,
                        'Remark' => 'Wallet Recharge - ' . $udf1,
                    ];
                    $topUpResponse = topUpWallet($walletInsert);
                }

                $user = User::where('AgencyID', $AgencyID)->where('id', $customer_id)->where('active', 1)->first();
                $Walletstatus = isset($topUpResponse['status']['success']) ? $topUpResponse['status']['success'] : 0;

                if ($Walletstatus == 1) {

                    $AgencyDetails = Users::getAgencyDetail($AgencyID);
                    $newPlain = $user->createToken('auth_token')->plainTextToken;

                    $currency = [
                        'CurrencyRate' => !empty(Session::get('CurrencyRate')) ? Session::get('CurrencyRate') : 1,
                        'CurrencyTitle' => !empty(Session::get('CurrencyTitle')) ? Session::get('CurrencyTitle') : 'INR',
                        'CurrencyId' => !empty(Session::get('CurrencyId')) ? Session::get('CurrencyId') : 1,
                    ];

                    if ($udf3 === 'Web-B2B' || $udf3 === 'Web-B2C' || $udf3 === 'Web-CRM' || $udf3 === 'GoerOne') {
                        $bookingProcess = AtomAES::decrypt($udf7, $AgencyDetails->api_key, $AgencyDetails->api_key);
                    } else {
                        $bookingProcess = decryptData($udf7, $AgencyDetails->api_key);
                    }
                    $bookingProcess = json_decode($bookingProcess, 1);

                    if ($udf2 == 1 && $Walletstatus == 1) {
                        Storage::disk('public')->put('logs/Webhook/redeem/' . $BookingID . '/' . $currentDate . '_redeemRequest.json', json_encode($bookingProcess));
                        // pr($bookingProcess);
                        // die;
                        $URL = $this->API_URL . '/api/loyalty/redeem';
                        $response = HttpRequest($URL, $bookingProcess, $AgencyID, $newPlain);
                        $resultSet = json_decode($response, 1);
                        Storage::disk('public')->put('logs/Webhook/redeem/' . $BookingID . '/' . $currentDate . '_redeemResponse.json', json_encode($resultSet));
                        return response()->json(['status' => 'success', 'resultSet' => $resultSet]);
                    }
                } else {
                    /// wallet recharge failed
                    $notification = [
                        'action_url' => '',
                        'title' => '❌ Recharge Failed',
                        'message' => 'Recharge failed with reference ID ' . $BookingID . '. If money was deducted, it will be refunded within 2-5 working days. For details please contact with our support team.'
                    ];
                }


                $allResponse = [
                    'response' => $response,
                    'topUpResponse' => $topUpResponse,
                    'bookingProcess' => $bookingProcess,
                ];

                Storage::disk('public')->put('logs/Webhook/redeem/' . $BookingID . '_' . $currentDate . '_redeemSuccess.json', json_encode($allResponse));
            } else {
                $notification = [
                    'action_url' => '',
                    'title' => '❌ Payment Failed',
                    'message' => 'Recharge failed with reference ID ' . $BookingID . '. If money was deducted, it will be refunded within 2-5 working days. For details please contact with our support team.'
                ];
            }


            return response()->json(['status' => 'success']);
        } catch (\Throwable $th) {
            Storage::disk('public')->put('logs/Webhook/easebuzz/' . $currentDate . '_catch_error.json', json_encode($th->getMessage()));
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 502,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }
}
