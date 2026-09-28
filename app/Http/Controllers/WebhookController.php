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
            $post = $request->all();
            $pgsource = isset($post['pgsource']) ? $post['pgsource'] : '4';
            $type = isset($post['type']) ? $post['type'] : '';
            // $bookingProcess = AtomAES::decrypt('96f53c4323348f6642a5bd8356302d6a82838308dce85cecc9a61175245f6b2a5a07ead3c97d4e622a1811dee980b5a0bf0f1f8fdc648bf65bfd695521e1a0477e65ddcc151f8f74fa341db1241d50e7743d8d6077f444f66257a2194290f421f3e169aa2e6086a71b6e7ea342554666', 'F4yYTHir2gkdhUjG7JGM8AMerjxUqZF4Cyy7aKT4XmXGuKILo4AddlnuVwMycMw7w11OZd9RA8STgF9o', 'F4yYTHir2gkdhUjG7JGM8AMerjxUqZF4Cyy7aKT4XmXGuKILo4AddlnuVwMycMw7w11OZd9RA8STgF9o');
            // // $bookingProcess = decryptData('c2NoUWV5TzlzOUw5bXAwZU9lVWlCTXRiMVZHSW1TNjFTNURubGdqS24xNEx0ajh4WnRJQXNoSmw0VHJXODFaNWNCbVAzLzJXMG1lNTFwQi84ZzZLN1MvOFRYb1ZWN1lTbk1nRVo5S3BaaHprUGF4UmdZMGZGbWZTcDlwRFpjQ1BkV2tROXdJSks2Y3llbjBoYVRWZUJkOUxWWjZBeWN0UldaSCtVWlBndDJjaGhwUnRIWTVpek5NKzdleS9iQ0pM', 'Ndzg2GHZ2277pGrAYS0NZJgTOiMXF2z8VmoWCRIay8sPbf0Xig4rTlSy57ObY1HYK2QrxAxnEFc8zvOj');
            // pr($bookingProcess);

            Storage::disk('public')->put('logs/Webhook/easebuzz/' . $currentDate . '_response.json', json_encode($post));

            $post = json_decode('{"key":"5D2J88C2J","furl":"https:\/\/goertrip.club\/payment\/payment-success?planType=1&BookingID=TXN1790575630889","hash":"42adba63d32ffec963f88914ea5ab183bed2733f3d249502a52d64af0a9d4592d175999aab8e76a4c2a7267b5a4039bb8b2a0fb1e25abe61bdfcc01485b55bab","mode":"NB","surl":"https:\/\/goertrip.club\/payment\/payment-success?planType=1&BookingID=TXN1790575630889","udf1":"TXN1790575630889","udf2":"1","udf3":"GoerOne","udf4":"312","udf5":"42","udf6":"INR","udf7":"ecf493fe4ca303e10215c3c3a167f20fca56f213a639958e54eece5b3176f41b1cfe40b43f1be229caf62c45d61c79fec009a3d93a6c4fe0a1c4c28725874160ef25f17521094849ae3c920b5148e76c3addb45e198029744190f5250e86dc242539612433dc5d2f9b31603a1fbbf684","udf8":null,"udf9":null,"email":"Mdsabirnoor@gmail.com","error":"Transaction is successful.","phone":"8447455883","txnid":"TXN1790575630889","udf10":null,"amount":"1700.0","status":"success","upi_va":"NA","PG_TYPE":"NA","addedon":"2026-09-28 06:07:17.000000","cardnum":"NA","bankcode":"NA","auth_code":null,"bank_name":"Axis Bank","card_type":"NA","easepayid":"S2609280761V4Z","firstname":"Md Sabir Md Sabir","productinfo":"Store Purchase","service_tax":"7.65","auth_ref_num":"NA","bank_ref_num":"872295303959","cardCategory":"NA","issuing_bank":"NA","name_on_card":"NA","discount_code":"NA","error_Message":"Transaction is successful.","merchant_logo":"NA","payment_source":"Easebuzz","service_charge":"42.5","unmappedstatus":"NA","discount_amount":"0.0","net_amount_debit":"1700.0","payment_category":"DEFAULT","settlement_amount":"1649.85","cancellation_reason":"NA","cash_back_percentage":"50.0","deduction_percentage":"2.5"}', 1);
            if ($pgsource == '2') {
                $payloadData = self::SetDataCashfree($post);
            } elseif ($pgsource == '5') {
                $encData = $post; //json_decode('{"encData":"0CD63904F407DC4D24167F8D8F2BEE997C80D4536815B71C1EDDB8FDCBACE8B914473F05297ED52048B1C4FC65BC50B342C5CF780CB2A9D86D7ECCB8C3379FBE51F2A539C5823C4066D695A161035C0A2C51F5281B9F072B840EA0F3824C17C0AF4A1C7E896A2821BBE5131784254DD24B4B4F5F0E9AD327ED4BE70B9DE6B125D5485A8F4531342DB40243CC244F653203C154A84DB66A3F962DED42AC226E2AD4DD1976C9076FFCDFE705C3CDBFEE0761B72E53C057C0ADAA9BBB41F240AFECEC2876BFEA84AC10B5E4DD8ACF39611D7E868B25CFEB29A0761B5C65C6298F12962771740F60ABF67427071A430E0EEA4222878848DE149FFDFABDC6D796E9CB2BFFACC958FF87A80DA64131CA65DA455E2D74491576E91FAF2881AEF4A93A46DF3E7A62567E737A1B514494C3D2B305AD4B40951C7E132DFE1C0D2B372058B5D6AE16C80A9F52494C873CC2D6DCCCF5D8A48BAB85A25212AE4296B5A692B85FD97BED79150C345DBEAC376466BE9F5E7CBBE99369EDEBDE4BAFEEAE4D2D2BC40E211AA261BF6711D4D9CD58518E23EB7A6CED22385481E54F74E0AFB7CDBB3CB113E830BD940370D39D5D7710C7CBA1146AB72BB984031FAD83583540D7C9754E655297F44F0596D6249F965B833517C4ECA41FFCE4C37B8CC4C03173170FBA712087AF9CBCF4B93625EFB1499F71824352B1ECDC5E97946BF3963CAE1535D3C76764972394D4EA62A492DA253F7B52ED1B0AA1B60CBB0B06394852EE569C9DE228AC79EF0AEF426C091B7F99F57410EBA02C9F0FB218F2E450ADB1E56041F565CE476BDFDA3ED9F5BA4C430CB38F6DBF02EC534B9492AA8EE0B3655B362F6955B3E77D0B7BECF064B6AC7B2584D34A3CFEC826427629551613C445F06AF1A69C9AD1CF1F5BF0C26DB5C04B0018DDFE8FF1661122A965419C887AA73E1AB54CADD56BA778005BABF7D7A545C9E9362DEB35FE178AC83DAFCD039F44A38C947AC656BDCEBC6E137D3A7A5D1887432C2DC02C7465A21FC3E5FDC08F104A64E187F85C3513D45E51C797246B76FE218DB6AD6A807CEDC3DDAD2D3821C85FCFD01575F9195C915A53096A68EDFE742D5B69F1B82D4119426C6306B680340E82225E99E75AAF063B990390280DF7BC39A8CBE6C55D779572D12AEFCB5182A191B7EA41DD55A98503580A0F9B927BEAB84961D32BE10A5986688FBBDF695641B8666F1CD1C16A6D965231A106401A2445121B7A1C1E6D2F1376EDB90A782F2591A4AE02EA180407F5BE523E488FAA279CB336FC120B4E4708ADF24AAF3A735C004B745D6E2BB8B782267A2E4E440FAE3F90DA7ACDCF821BAC116A0EAF960AF9DFB879C847278E7EBCCCD6E9C435DC7882B81A27024F4D949A456E5FD899DD19752495FEB2AC2DF4858163AEE3D7EE43F1B98BD162707A98659B7C10A114AE4D20882D9A2748C408742EE217C9CDE9210C20D05B25ED2591F8B52C979B5F11C8F5EDDB4EC1E2888AF7FB8192D9BEA5D35D078C36C261AD9F4868FC924A9890E0765FEAB367CFFA74C5B973D50EB3B0824FAB41D3FBA59471DF125D3DC1C015D0EF5196C4BB6E389514CF7089285990368BFA260EE804C82C26C4511D2ECADD53087CAF75E4911E1166784056DC6CFD118ED37541D5A7E4FC6E18A0D214CEBD023EA1DB6A20C4B50765CA9E63478EAE4BD13D39","merchId":"445842"}', 1);
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
            $existsuccess = null; //PaymentTransaction::where('agency_id', $AgencyID)->where('status', 'success')->where('txnid', $txnid)->exists();
            $transaction = PaymentTransaction::insertGetId($transactionData);

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
                $Walletstatus = 1; //isset($topUpResponse['status']['success']) ? $topUpResponse['status']['success'] : 0;

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
                    $isUpgradeToVip = isset($bookingProcess['isUpgradeToVip']) ? $bookingProcess['isUpgradeToVip'] : 0;
                    $isHoldconfirm = isset($bookingProcess['isHoldconfirm']) ? $bookingProcess['isHoldconfirm'] : 0;
                    $paymentType = isset($bookingProcess['paymentType']) ? $bookingProcess['paymentType'] : '';
                    $tenure_months = isset($bookingProcess['tenure_months']) ? $bookingProcess['tenure_months'] : 0;


                    if ($udf2 == 1 && $Walletstatus == 1) { // Flight booking process

                        echo $URL = $this->API_URL . '/api/loyalty/redeem';
                        die;
                        $response = HttpRequest($URL, $bookingProcess, $AgencyID, $newPlain);
                        $resultSet = json_decode($response, 1);
                        pr($bookingProcess);
                        pr($resultSet);
                        die;
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

                Storage::disk('public')->put('logs/Webhook/bookings/' . $BookingID . '_' . $currentDate . '_bookingSuccess.json', json_encode($allResponse));
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
