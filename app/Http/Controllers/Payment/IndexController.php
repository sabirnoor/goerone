<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\PaymentDetail;
use Razorpay\Api\Api;
use App\Helpers\Helper;
use App\Models\Busbooking;
use App\Models\customer_travel_proposal;
use App\Models\customer_travel_query;
use App\Models\HotelBookingModel;
use App\Models\FlightBookingModel;
use App\Models\Invoices;
use App\Models\WalletModel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Models\AtomAES;
use App\Models\BookingLimit;
use App\Models\mst_payments_setting;
use App\Models\Users;
use Illuminate\Support\Facades\DB;

class IndexController extends Controller
{

    public $APP_NAME;
    public $APP_URL;
    public $PaymentSecretPwd;
    public $PaymentMerchantId;
    public $SECURITYKEY;

    private $merchantKey;
    private $salt;
    private $cashfreeURL;

    public function __construct()
    {
        $this->APP_URL = env('APP_URL');
        $this->APP_NAME = env('APP_NAME');
        $this->PaymentMerchantId = env('PaymentMerchantId');
        $this->PaymentSecretPwd = env('PaymentSecretPwd');
        $this->SECURITYKEY = env('SECURITYKEY');

        $this->merchantKey = env('EASEBUZZ_MERCHANT_KEY');
        $this->salt = env('EASEBUZZ_SALT');
        $this->cashfreeURL = 'https://sandbox.cashfree.com/pg';
    }

    private function generateHash($posted, $CheckSetting = null, $IsProd = 0)
    {
        $merchantSaltCheck = (isset($CheckSetting->KeySecret) && !empty($CheckSetting->KeySecret)) ? $CheckSetting->KeySecret : $this->salt;
        $saltSecret = (isset($CheckSetting->KeySecret) && $IsProd == 1) ? $CheckSetting->KeySecret : $merchantSaltCheck;
        $hash_sequence = "key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5|udf6|udf7|udf8|udf9|udf10";

        // make an array or split into array base on pipe sign.
        $hash_sequence_array = explode('|', $hash_sequence);
        $hash = null;

        // prepare a string based on hash sequence from the $params array.
        foreach ($hash_sequence_array as $value) {
            $hash .= isset($posted[$value]) ? $posted[$value] : '';
            $hash .= '|';
        }

        $hash .= $saltSecret;
        #echo strtolower(hash('sha512', $hash));
        // generate hash key using hash function(predefine) and return
        return strtolower(hash('sha512', $hash));
    }

    public function index(Request $request)
    {
        $paymentData = json_decode($request->paymentData, 1);
        $PGTYPE = $paymentData['PGTYPE'];
        $paymentData['key'] = $this->merchantKey;
        $paymentData['firstname'] = $paymentData['UserData']['name'];
        $paymentData['email'] = $paymentData['UserData']['email'];
        $paymentData['phone'] = $paymentData['UserData']['mobile'];

        return view("Payment/index", compact('paymentData'));
        // echo '<pre>';print_r($paymentData);
        // die('thanks');
    }
    public function razorpay(Request $request)
    {
        $post = $request->all();

        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        $UserSysId = $request->user()->id;
        $email = $request->user()->email;
        // echo '<pre>';print_r($request->all());die('ddddd');
        $AgencySysId = (int) $post['AgencySysId'];
        $amount = (float) $post['amount'];

        $UserData = $post["UserData"];
        $stringData = $post["stringData"];
        $amount = $post["amount"];
        $paymentURL = $post["paymentURL"];
        $GUID = $post["guid"];
        $Firstname = $UserData["name"];
        $email = $UserData["email"];
        $phone = $UserData["mobile"];
        $TPSysId = $post["TPSysId"];
        $udf5ReturnURL = $post["strReturnURL"];
        $AgencySysId = $post["AgencySysId"];
        $securecode = $post["walletCode"];
        $lastInsertId = $post["lastInsertId"];
        $FLBookingID = $post["FLBookingID"];
        $CustomerSysId = $UserData["CustomerSysId"];
        $checkCode = Helper::walletCode($lastInsertId, $GUID, $amount, $AgencySysId, $TPSysId, $CustomerSysId, $stringData);

        if ($securecode == $checkCode) {
            $logaPath = $this->APP_URL . '/images/logo.png';
            $callback_url = $this->APP_URL . '/payment/secureserver';
            $PGTYPE = $post['PGTYPE'];
            if ($PGTYPE == 'EASEBUZZ') {
                $amount = number_format(round((float)$post['amount'], 2), 2, '.', '');
                $data = [
                    'key' => $this->merchantKey,
                    'txnid' => $post['txnid'],
                    'amount' => $amount,
                    'productinfo' => $post['productinfo'],
                    'firstname' => $post['firstname'],
                    'email' => $post['email'],
                    'phone' => $post['phone'],
                    'enviroment' => isset($post['enviroment']) && !empty($post['enviroment']) ? $post['enviroment'] : 'test',
                    'surl' => $this->APP_URL . "/payment/secureserver",
                    'furl' => $this->APP_URL . "/payment/secureserver",
                    //'hash' => $this->generateHash($post),
                ];
                for ($i = 1; $i <= 5; $i++) {
                    $field = 'udf' . $i;
                    $data[$field] = $post[$field];
                }
                $data['hash'] = $this->generateHash($data);
                $url = ($data['enviroment'] == 'test') ? 'https://testpay.easebuzz.in/payment/initiateLink' : 'https://pg.easebuzz.in/payment/initiateLink';
                $curl_result = self::_curlCall($url, http_build_query($data));
                //$curl_result = json_decode('{"status":1,"data":"bb73c37bc17594e8ab9cc720ca225d5f2e6a0b16bd1b07965035c0a52c73dfa8"}');
                $accesskey = ($curl_result->status === 1) ? $curl_result->data : null;
                if ($curl_result->status === 1) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'success',
                        'accesskey' => $accesskey,
                        'key' =>  $this->merchantKey,
                        'enviroment' =>  $data['enviroment'],
                        'Data' => $curl_result,
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 404,
                        ],
                        'message' => $curl_result->data,
                        'Data' => $curl_result->data,
                    ]);
                }
            } else {
                $orderNote = "Booking";
                $secretKey = $this->PaymentSecretPwd;
                $appId = $this->PaymentMerchantId;
                $getGuid = strtoupper(Str::uuid()->toString());
                // $amount = 3458;
                $postData = array(
                    "appId" => $appId,
                    "orderId" => $GUID,
                    "orderAmount" => $amount,
                    "orderCurrency" => trim($post["txncurr"]),
                    "orderNote" => $orderNote,
                    "customerName" => $Firstname,
                    "customerPhone" => $phone,
                    "customerEmail" => $email,
                );
                // echo '<pre>';print_r($postData);die('ddddd');
                ksort($postData);
                $signatureData = "";
                foreach ($postData as $key => $value) {
                    $signatureData .= $key . $value;
                }
                $signature = hash_hmac('sha256', $signatureData, $secretKey, true);
                $signature = base64_encode($signature);
                $postData["signature"] = $signature;
                $insertArray = array(
                    "orderId" => $GUID,
                    "signature" => $signature,
                    "encoded_data" => Crypt::encryptString(json_encode($post)),
                    "GUID" => $getGuid
                );
                $amount = $amount * 100;
                $txncurr = trim($post["txncurr"]);
                $api = new Api($appId, $secretKey);
                $orderData = [
                    'receipt' => $GUID,
                    'amount' => round($amount, 2), // 39900 rupees in paise    
                    'currency' => $txncurr,
                    'payment_capture' => 1,
                    'notes' => [
                        'merchant_order_id' => $getGuid,
                        'AgencyID' => $AgencyID,
                        'UserSysId' => $UserSysId,
                        'email' => $email
                    ]
                ];
                // print_r($orderData);die;
                $razorpayOrder = $api->order->create($orderData);
                $razorpayOrderId = $razorpayOrder->id;
                $razordata = [
                    "key" => $appId,
                    "amount" => $amount,
                    "name" => trim($post["DisplayName"]),
                    "description" => trim($post["DisplayName"]),
                    "image" => $logaPath,
                    "prefill" =>
                    [
                        "name" => $UserData['name'],
                        "email" => $UserData['email'],
                        "contact" => $UserData['mobile']
                    ],
                    "notes" =>
                    [
                        "address" => "India",
                        "merchant_order_id" => $GUID,
                    ],
                    "theme" =>
                    [
                        "color" => "#FC9F84"
                    ],
                    "order_id" => $razorpayOrderId,
                    "callback_url" => $callback_url,
                    "redirect" => true
                ];

                $insertArray['orderId'] = $razorpayOrderId;
                $insertGetId = PaymentDetail::insertGetId($insertArray);
                if ($insertGetId) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'success',
                        'razorData' => $razordata,
                    ]);
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 404,
                        ],
                        'message' => 'Oops somethings went wrong',
                    ]);
                }
            }
            // print_r($insertArray);
            // print_r($razordata);
            // print_r($request->all());
            // die('thanks');
        } else {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 404,
                ],
                'message' => 'Invalid request',
            ]);
        }
    }


    public function _curlCall($url, $params_array)
    {
        // Initializes a new session and return a cURL.
        $cURL = curl_init();

        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);

        // Set multiple options for a cURL transfer.
        curl_setopt_array(
            $cURL,
            array(
                CURLOPT_URL => $url,
                CURLOPT_POSTFIELDS => $params_array,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1985.125 Safari/537.36',
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
                // CURLOPT_HTTPHEADER => array(
                //     'Accept: application/json',
                //     // "X-Forwarded-For: 106.223.181.243",
                //     // "Client-IP: 106.223.181.243",
                //     "X-Forwarded-For: 31.97.202.152",
                //     "Client-IP: 31.97.202.152",
                // ),
            )
        );

        // Perform a cURL session
        $result = curl_exec($cURL);


        // check there is any error or not in curl execution.
        if (curl_errno($cURL)) {
            $cURL_error = curl_error($cURL);
            if (empty($cURL_error))
                $cURL_error = 'Server Error';

            return array(
                'curl_status' => 0,
                'error' => $cURL_error
            );
        }

        $result = trim($result);
        $result_response = json_decode($result);

        return $result_response;
    }

    public function initiate_payment(Request $request)
    {
        $post = $request->all();

        $validator = Validator::make($request->all(), [
            'amount' => 'required',
            'txnid' => 'required',
            'productinfo' => 'required',
            'email' => 'required',
            'phone' => 'required',
            'name' => 'required',
            'surl' => 'required',
            'furl' => 'required',
            'securecode' => 'required',

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
        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        $UserSysId = $request->user()->id;

        $email = $request->user()->email;
        $AgencyDetails = Users::getAgencyDetail($AgencyID);
        $amount = $post['amount'];
        $phone = trim($post["phone"]);
        $email = trim($post["email"]);
        $txnid = trim($post["txnid"]);
        $name = trim($post["name"]);
        $productinfo = trim($post["productinfo"]);
        $securecode = $post["securecode"];
        $dataencrypt = [
            "txnid" => $txnid
        ];
        //pr($txnid . '===' . $txnid . '===' . $amount . '===' . $name . '===' . $phone . '===' . $email . '===' . $productinfo);
        $checkCode = AtomAES::walletCode($txnid, $txnid, $amount, $name, $phone, $email, $productinfo);
        $CheckSetting = mst_payments_setting::PGCredentialData($request->user());
        $ActivePG = isset($CheckSetting->ActivePG) ? $CheckSetting->ActivePG : '';
        $merchantKey = isset($CheckSetting->KeyID) ? $CheckSetting->KeyID : '';
        $KeySecret = isset($CheckSetting->KeySecret) ? $CheckSetting->KeySecret : '';
        // if ($AgencyID == 86) {
        // pr($securecode);
        // pr($checkCode);
        // pr($CheckSetting);
        // pr($request->user());

        // }
        $merchantKeysCheck = !empty($merchantKey) ? $merchantKey : $this->merchantKey;
        // die; "key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5|udf6|udf7|udf8|udf9|udf10"
        if ($securecode == $checkCode && $ActivePG == 4) {
            $amount = number_format(round((float)$post['amount'], 2), 2, '.', '');
            $data = [
                'key' => ($AgencyDetails->IsProd == 0) ? $merchantKeysCheck : $merchantKey,
                'txnid' => $post['txnid'],
                'amount' => $amount,
                'productinfo' => $post['productinfo'],
                'firstname' => $post['name'],
                'email' => $post['email'],
                'phone' => $post['phone'],
                // 'enviroment' => ($AgencyDetails->IsProd == 0) ? 'test' : 'prod',
                'surl' => isset($post['surl']) ? $post['surl'] : "",
                'furl' => isset($post['furl']) ? $post['furl'] : "",
                'udf1' => isset($post['udf1']) ? $post['udf1'] : "",
                'udf2' => isset($post['udf2']) ? $post['udf2'] : "",
                'udf3' => isset($post['udf3']) ? $post['udf3'] : "",
                'udf4' => isset($post['udf4']) ? $post['udf4'] : "",
                'udf5' => isset($post['udf5']) ? $post['udf5'] : "",
                'udf6' => isset($post['udf6']) ? $post['udf6'] : "",
                'udf7' => isset($post['udf7']) ? $post['udf7'] : "",
                'udf8' => isset($post['udf8']) ? $post['udf8'] : "",
                'udf9' => isset($post['udf9']) ? $post['udf9'] : "",
                'udf10' => isset($post['udf10']) ? $post['udf10'] : "",
                'show_payment_mode' => (isset($post['show_payment_mode']) && !empty($post['show_payment_mode'])) ? $post['show_payment_mode'] : "NB,DC,CC,MW,UPI,OM,EMI,CBT,BT,EI,PL,EMI",
            ];

            $data['hash'] = $this->generateHash($data, $CheckSetting, $AgencyDetails->IsProd);
            // unset($data['securecode']);

            $url = ($AgencyDetails->IsProd == 0) ? 'https://testpay.easebuzz.in/payment/initiateLink' : 'https://pay.easebuzz.in/payment/initiateLink';
            $curl_result = self::_curlCall($url, http_build_query($data));
            // if ($request->user()->id == 96) {
            //     pr(json_encode($data));
            //     pr($url);
            //     pr($curl_result);
            //     die;
            // }
            //$curl_result = json_decode('{"status":1,"data":"bb73c37bc17594e8ab9cc720ca225d5f2e6a0b16bd1b07965035c0a52c73dfa8"}');
            $accesskey = (isset($curl_result->status) && ($curl_result->status === 1)) ? $curl_result->data : null;
            if ($curl_result->status === 1) {
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'message' => 'success',
                    'accesskey' => $accesskey,
                    'key' =>  AtomAES::encrypt(isset($CheckSetting->KeyID) ? $CheckSetting->KeyID : '', $AgencyDetails->api_key, $AgencyDetails->api_key),
                    'enviroment' => ($AgencyDetails->IsProd == 0) ? 'test' : 'prod',
                    'ActivePG' => $ActivePG,
                    'Data' => $curl_result,
                ]);
            } else {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 404,
                    ],
                    'message' => $curl_result->data,
                    'Data' => $curl_result->data,
                    // 'IP' => $request->server('SERVER_ADDR')
                ]);
            }
        } elseif ($securecode == $checkCode && $ActivePG == 2) {
            if ($AgencyDetails->IsProd == 1) {
                $baseUrl = PGPRODURL();
            } else {
                $baseUrl = PGTESTURL();
            }

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-version' => $baseUrl['CASHFREE_API_VERSION'], //env('CASHFREE_API_VERSION'),
                'x-client-id' => $merchantKey ?? $baseUrl['CASHFREE_CLIENTID'], //env('CASHFREE_CLIENT_ID'),
                'x-client-secret' => $KeySecret ?? $baseUrl['CASHFREE_CLIENTSECRET'], //env('CASHFREE_CLIENT_SECRET'),
            ])->post($baseUrl['CASHFREE_URL'] . '/orders', [
                'order_id' => $post['txnid'],
                'order_amount' => $post['amount'],
                'order_currency' => 'INR',
                'customer_details' => [
                    'customer_id' => (string)$request->user()->id ?? 'cust_' . uniqid(),
                    'customer_name' => $post['name'],
                    'customer_phone' => $post['phone'],
                    'customer_email' => $post['email'],
                ],
                'order_meta' => [
                    'return_url' => $request->surl
                ],
                'order_tags' => [
                    'udf1' => isset($post['udf1']) ? (string)$post['udf1'] : "",
                    'udf2' => isset($post['udf2']) ? (string)$post['udf2'] : "",
                    'udf3' => isset($post['udf3']) ? (string)$post['udf3'] : "",
                    'udf4' => isset($post['udf4']) ? (string)$post['udf4'] : "",
                    'udf5' => isset($post['udf5']) ? (string)$post['udf5'] : "",
                    'udf6' => isset($post['udf6']) ? (string)$post['udf6'] : "",
                    'udf7' => isset($post['udf7']) ? (string)$post['udf7'] : "",
                    'udf8' => isset($post['udf8']) ? (string)$post['udf8'] : "",
                    'udf9' => isset($post['udf9']) ? (string)$post['udf9'] : "",
                    'udf10' => isset($post['udf10']) ? (string)$post['udf10'] : "",
                    'productinfo' => $post['productinfo'] ?? '',
                ]
            ]);

            if ($response->successful()) {
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'message' => 'success',
                    'accesskey' => $response['payment_session_id'],
                    'key' =>  AtomAES::encrypt(isset($CheckSetting->KeyID) ? $CheckSetting->KeyID : '', $AgencyDetails->api_key, $AgencyDetails->api_key),
                    'enviroment' => ($AgencyDetails->IsProd == 0) ? 'sandbox' : 'production',
                    'order_id' => $response['cf_order_id'],
                    'ActivePG' => $ActivePG,
                ]);
                // return response()->json([
                //     'payment_session_id' => $response['payment_session_id'],
                //     'order_id' => $response['cf_order_id']
                // ]);
            }
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $response->body(),
                'Data' => $response->body(),
            ]);
            // return response()->json(['error' => $response->body()], 500);
            // pr($ActivePG);
            // pr($post);
            // pr($request->user());
            // die;
        } elseif ($securecode == $checkCode && $ActivePG == 5) {
            if ($AgencyDetails->IsProd == 1) {
                $baseUrl = PGPRODURL();
            } else {
                $baseUrl = PGTESTURL();
            }
            $data = $request->all();
            $data['login'] = $CheckSetting->KeyID ?? '';
            $data['password'] = $CheckSetting->KeySecret ?? '';
            $data['prod_id'] = $CheckSetting->Environment ?? '';
            $data['txnCurrency'] = $data['udf6'] ?? '';
            $data['txnId'] = $data['txnid'] ?? '';
            $data['amount'] = number_format($data['amount'], 2, '.', '');
            $data['encKey'] = $CheckSetting->reqAESKey ?? '';
            $data['decKey'] = $CheckSetting->resAESKey ?? '';
            $data['payUrl'] = $baseUrl['ATOM_URL'] ?? '';
            $data['date'] = date('Y-m-d H:i:s') ?? '';

            $jsondata = '{
                "payInstrument": {
                    "headDetails": {
                        "version": "OTSv1.1",      
                        "api": "AUTH",  
                        "platform": "FLASH"	
                    },
                    "merchDetails": {
                        "merchId": "' . $data['login'] . '",
                        "userId": "",
                        "password": "' . $data['password'] . '",
                        "merchTxnId": "' . $data['txnId'] . '",      
                        "merchTxnDate": "' . $data['date'] . '"
                    },
                    "payDetails": {
                        "amount": "' . $data['amount'] . '",
                        "product": "' . $data['prod_id'] . '",
                        "custAccNo": "213232323",
                        "txnCurrency": "' . $data['txnCurrency'] . '"
                    },	
                    "custDetails": {
                        "custEmail": "' . $data['email'] . '",
                        "custMobile": "' . $data['phone'] . '"
                    },
                    "extras": {
                        "udf1": "' . $data['udf1'] . '",  
                        "udf2": "' . $data['udf2'] . '",  
                        "udf3": "' . $data['udf3'] . '", 
                        "udf4": "' . $data['udf4'] . '",  
                        "udf5": "' . $data['udf5'] . '",
                        "udf6": "' . $data['udf6'] . '",
                        "udf7": "' . $data['udf7'] . '",
                        "udf8": "' . $data['udf8'] . '",
                        "udf9": "' . $data['udf9'] . '",
                        "udf10": "' . $data['udf10'] . '"
                    }
                }  
            }';

            $encData = AtomAES::ATOMencrypt($jsondata, $data['encKey'], $data['encKey']);

            try {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $data['payUrl'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_SSL_VERIFYPEER => 1,
                    CURLOPT_CAINFO => dirname(__FILE__) . '/cacert.pem', //added in Controllers folder
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_POSTFIELDS => "encData=" . $encData . "&merchId=" . $data['login'],
                    CURLOPT_HTTPHEADER => array(
                        "Content-Type: application/x-www-form-urlencoded"
                    ),
                ));
                $atomTokenId = null;
                $response = curl_exec($curl);

                if (!empty($response)) {
                    $getresp = explode("&", $response);
                    $encresp = substr($getresp[1], strpos($getresp[1], "=") + 1);

                    $decData = AtomAES::ATOMdecrypt($encresp, $data['decKey'], $data['decKey']);

                    if (curl_errno($curl)) {
                        $error_msg = curl_error($curl);
                        // echo "error = " . $error_msg;
                        return response()->json([
                            'status' => [
                                'success' => false,
                                'httpStatus' => 400,
                            ],
                            'message' => $error_msg,
                            'Data' => $error_msg,
                        ]);
                    }
                    if (isset($error_msg)) {
                        // echo "error = " . $error_msg;
                        return response()->json([
                            'status' => [
                                'success' => false,
                                'httpStatus' => 400,
                            ],
                            'message' => $error_msg,
                            'Data' => $error_msg,
                        ]);
                    }
                    curl_close($curl);
                    $res = json_decode($decData, true);

                    if ($res) {
                        if ($res['responseDetails']['txnStatusCode'] == 'OTS0000') {
                            $atomTokenId = $res['atomTokenId'] ?? '';
                            return response()->json([
                                'status' => [
                                    'success' => true,
                                    'httpStatus' => 200,
                                ],
                                'message' => 'success',
                                'accesskey' => $atomTokenId,
                                'key' =>  AtomAES::encrypt(isset($CheckSetting->KeyID) ? $CheckSetting->KeyID : '', $AgencyDetails->api_key, $AgencyDetails->api_key),
                                'enviroment' => ($AgencyDetails->IsProd == 0) ? 'uat' : 'live',
                                'order_id' => $atomTokenId,
                                'ActivePG' => $ActivePG,
                            ]);
                        } else {
                            return response()->json([
                                'status' => [
                                    'success' => false,
                                    'httpStatus' => 302,
                                ],
                                'message' => $res,
                                'Data' => $res,
                            ]);
                        }
                    }
                } else {
                    return response()->json([
                        'status' => [
                            'success' => false,
                            'httpStatus' => 5001,
                        ],
                        'message' => $response,
                        'Data' => $response,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('ATOM token generation failed', ['error' => $e->getMessage()]);
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 500,
                    ],
                    'message' => $e->getMessage(),
                    'Data' => $e->getMessage(),
                ]);
            }
        } else {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 404,
                ],
                'message' => 'Invalid request',
            ]);
        }
    }

    public function verifyPayment(Request $request)
    {
        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        $AgencyDetails = Users::getAgencyDetail($AgencyID);
        $CheckSetting = mst_payments_setting::PGCredentialData($request->user());
        $merchantKey = isset($CheckSetting->KeyID) ? $CheckSetting->KeyID : '';
        $KeySecret = isset($CheckSetting->KeySecret) ? $CheckSetting->KeySecret : '';
        $post = $request->all();
        $orderId = $request->txnid;
        if ($AgencyDetails->IsProd == 1) {
            $baseUrl = PGPRODURL();
        } else {
            $baseUrl = PGTESTURL();
        }
        if (!$orderId) {
            return response()->json(['error' => 'order_id is required'], 400);
        }

        // Call Cashfree GET order endpoint
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'x-api-version' => $baseUrl['CASHFREE_API_VERSION'],
            'x-client-id' => $merchantKey ?? $baseUrl['CASHFREE_CLIENTID'],
            'x-client-secret' => $KeySecret ?? $baseUrl['CASHFREE_CLIENTSECRET'],
        ])->get($baseUrl['CASHFREE_URL'] . '/orders/' . $orderId);

        if ($response->successful()) {
            $post['status'] = ($response['order_status'] == 'PAID') ? 'success' : '';
            $post['order_id'] = $response['order_id'] ?? null;
            $post['cf_order_id'] = $response['cf_order_id'] ?? null;
            return response()->json($post);
            // return response()->json([
            //     'status' => ($response['order_status'] == 'PAID') ? 'success' : '',
            //     'txnid' => $response['order_id'] ?? null,
            //     'order_id' => $response['order_id'] ?? null,
            //     'order_status' => $response['order_status'] ?? null,
            //     'cf_order_id' => $response['cf_order_id'] ?? null,
            //     'order_amount' => $response['order_amount'] ?? null,
            //     'data' => $response->json()
            // ]);
        }
        $post['status'] = 'paymentfailed';
        $post['error'] = $response->body();;
        return response()->json($post, $response->status());
    }
    public function atomresponse(Request $request)
    {

        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        $data = $request->encData ?? '';
        $CheckSetting = mst_payments_setting::PGCredentialData($request->user());
        $resAESKey = $CheckSetting->resAESKey ?? '';
        // change decryption key below for production
        $decData = AtomAES::ATOMdecrypt($data, $resAESKey, $resAESKey);

        $jsonData = json_decode($decData, true);

        if ($jsonData['payInstrument']['responseDetails']['statusCode'] == 'OTS0000') {
            echo 'Payment status = Transaction Successful';
            echo "<br>";
            echo 'Transaction id = ' . $jsonData['payInstrument']['merchDetails']['merchTxnId'];
            echo "<br>";
            echo 'Transaction date = ' . $jsonData['payInstrument']['merchDetails']['merchTxnDate'];
            echo "<br>";
            echo 'Bank transaction id = ' . $jsonData['payInstrument']['payModeSpecificData']['bankDetails']['bankTxnId'];
        } else {
            echo 'Payment status = Transaction Failed';
        }
        echo "<pre>";
        print_r($jsonData);
        die;
    }
}
