<?php

use App\Models\AgentCreditLimit;
use App\Models\CreditPaymentHistory;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Models\Users;
use App\Models\WalletModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

if (!function_exists('helper_test')) {
    function helper_test()
    {
        return "checked";
    }
}
function pr($data)
{
    echo '<pre>';
    print_r($data);
    echo '</pre>';
}


function OrderStatus()
{
    return [
        0 => "PENDING",
        1 => "CONFIRMED",
        2 => "PROCESSING",
        3 => "DISPATCHED",
        4 => "DELIVERED",
        5 => "CANCELLED",
        6 => "REFUND INITIATED",
        7 => "REFUNDED",
    ];
}
function requestType()
{
    return [
        8 => "CANCELLATION",
        7 => "PARTIAL CANCELLATION",
        6 => "Full Refund Under DGCA Policy",
        11 => "SSR",
        12 => "Cancellation Quotation",
        13 => "Reissue Quotation",
        14 => "REISSUE",
        15 => "FARE_CHANGE",
        16 => "MISCELLANEOUS",
        17 => "NO_SHOW",
        18 => "VOIDED",
        19 => "CORRECTION",
    ];
}
function AirSupplierType()
{
    return [
        1 => "TRIPJACK",
        2 => "TBO",
        3 => "SF/GF",
        4 => "BHASIN",
        5 => "OFFLINE",
        6 => "RIYA",
        7 => "GOFLYSMART",
        8 => "TRAVELOPEDIA",
        9 => "GROUP TICKETS",
        10 => "GOSCANNER",
    ];
}

function PaymentMode()
{
    return [
        0 => "Cash",
        1 => "Bank Transfer",
        2 => "Bank Remittance",
        3 => "Cheque",
        4 => "Credit Card",
        5 => "UPI",
        6 => "Recharge",
        7 => "Commission",
        8 => "Refund",
        9 => "Online Wallet",
        10 => "Markup",
        11 => "Paid For Order",
        12 => "Paid For BNPL Pending",
        13 => "Referral Earning",
        14 => "Profit",
    ];
}
function DefaultTax()
{
    return [
        0 => ["name" => "GST 0%", 'value' => 0],
        1 => ["name" => "GST 5%", 'value' => 5],
        2 => ["name" => "GST 12%", 'value' => 12],
        3 => ["name" => "GST 18%", 'value' => 18],
        4 => ["name" => "GST 28%", 'value' => 28]
    ];
}

function PlanType()
{
    return [
        1 => "Flight",
        2 => "Hotel",
        3 => "Package",
        4 => "Miscellaneous",
        5 => "Group Flight",
        6 => "Bus",
        7 => "SightSeeing",
        8 => "Transfer",
        9 => "Visa",
        10 => "Loyalty Payment",
    ];
}

function BannerScreenType()
{
    return [
        1 => "Home",
        2 => "Search",
        3 => "Review",
        4 => "Addvertise",
    ];
}
function UserType()
{
    return [
        0 => "B2C",
        1 => "Agency",
        2 => "B2B",
        3 => "SuperAdmin",
        4 => "Agency Staff",
        6 => "Fanchise",
    ];
}
function PaymentType()
{
    return [
        1 => "Razorpay",
        2 => "Cashfree",
        // 3 => "Paypal",
        4 => "Easebuzz",
        5 => "ATOM",
    ];
}
function TypeOfPromoCode()
{
    return [
        1 => "First Type Booking",
        2 => "Student",
        3 => "Arm Force",
        4 => "Normal Domestic",
        5 => "Normal International",
        6 => "Special Fare / Group Fare",
        7 => "All",
    ];
}
function cleanImageName($originalName)
{
    // Remove any path information
    $filename = pathinfo($originalName, PATHINFO_FILENAME);

    // Convert to lowercase
    $filename = mb_strtolower($filename);

    // Replace spaces, underscores, and other special characters
    $filename = preg_replace("/[^a-z0-9\-]/", '-', $filename);

    // Remove duplicate dashes
    $filename = preg_replace("/\-+/", '-', $filename);

    // Trim dashes from beginning and end
    $filename = trim($filename, '-');

    // Get the original extension
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);

    // Return cleaned name with extension
    return $filename . '.' . $extension;
}

function isLeapYear($year)
{
    if ($year % 4 == 0) {
        if ($year % 100 == 0)
            return ($year % 400 == 0) ? true : false;
        return true;
    }
    return false;
}


function sanitize_data($input_data)
{
    $searchArr = array("document", "write", "alert", "%", "@", "$", ";", "+", "|", "#", "<", ">", ")", "(", "'", "\'", ",");
    $input_data = str_replace("script", "", $input_data);
    $input_data = str_replace("iframe", "", $input_data);
    $input_data = str_replace($searchArr, "", $input_data);
    return htmlentities(stripslashes($input_data), ENT_QUOTES);
}

function decryptData($encryptedValue, $securityKey)
{
    // Step 1: Extract AES key (32 chars) and IV (16 chars)
    $aesKey = substr($securityKey, 0, 32);
    $aesIv  = substr($securityKey, 0, 16);

    // Step 2: Undo Dart's extra base64 wrapping
    $decodedBase64String = base64_decode($encryptedValue);  // gives encrypted.base64 as string
    $cipherText = base64_decode($decodedBase64String);      // actual cipher bytes

    // Step 3: Decrypt using AES-256-CBC
    $decrypted = openssl_decrypt(
        $cipherText,
        "AES-256-CBC",
        $aesKey,
        OPENSSL_RAW_DATA,
        $aesIv
    );

    return $decrypted;
}

function minutes($time)
{
    $parts = explode(':', $time);

    // If format is HH:MM
    if (count($parts) == 2) {
        list($h, $m) = $parts;
        return ($h * 60) + $m;
    }

    // If format is HH:MM:SS
    if (count($parts) == 3) {
        list($h, $m, $s) = $parts;
        return ($h * 60) + $m + ($s / 60);
    }

    return 0; // invalid format
}

function GetLogo($user)
{
    $baseUrl = url('/');
    $hostname = request()->getHost();
    $logos = '';

    if ($user) {
        // user-based logo
        $logos = $baseUrl . '/storage/upload/' . $user->id . '/logo/' . $user->details->logo;
    } else {
        if ($hostname == 'crm.goertrip.club') {
            $logos = $baseUrl . '/images/goertrip/logo.png';
        } elseif ($hostname == 'crm.ziatravels.co.in') {
            $logos = $baseUrl . '/images/ziatravels/logo.png';
        } elseif ($hostname == 'admin.clickpaytrip.com') {
            $logos = $baseUrl . '/images/clickpaytrip/logo.png';
        }
    }
    return $logos;
}
function generateCardNumber($userId)
{
    // Get current date/time components
    $now = now(); // Laravel Carbon instance
    $hours   = str_pad($now->format('H'), 2, '0', STR_PAD_LEFT);
    $minutes = str_pad($now->format('i'), 2, '0', STR_PAD_LEFT);
    $seconds = str_pad($now->format('s'), 2, '0', STR_PAD_LEFT);
    $day     = str_pad($now->format('d'), 2, '0', STR_PAD_LEFT);
    $month   = str_pad($now->format('m'), 2, '0', STR_PAD_LEFT);

    // Combine date/time components (12 digits)
    $timeDigits = $month . $day . $hours . $minutes . $seconds;

    // Generate random 4-digit number
    $randomDigits = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

    // Combine userId + timeDigits + randomDigits
    $cardNumber = substr($userId . $timeDigits . $randomDigits, 0, 16);

    // Format (keep digits only, no extra spacing like React)
    $formattedNumber = trim($cardNumber);

    return $formattedNumber;
}

function getDateFormate($date)
{
    $dateExp = explode('/', $date);
    return $dateExp[2] . '-' . $dateExp[1] . '-' . $dateExp[0];
}

function encrypts($data = '', $key = NULL, $salt = "")
{
    if ($key != NULL && $data != "" && $salt != "") {

        $method = "AES-256-CBC";

        //Converting Array to bytes
        $iv = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
        $chars = array_map("chr", $iv);
        $IVbytes = join($chars);


        $salt1 = mb_convert_encoding($salt, "UTF-8"); //Encoding to UTF-8
        $key1 = mb_convert_encoding($key, "UTF-8"); //Encoding to UTF-8

        //SecretKeyFactory Instance of PBKDF2WithHmacSHA1 Java Equivalent
        $hash = openssl_pbkdf2($key1, $salt1, '256', '65536', 'sha1');

        $encrypted = openssl_encrypt($data, $method, $hash, OPENSSL_RAW_DATA, $IVbytes);

        return bin2hex($encrypted);
    } else {
        return "String to encrypt, Salt and Key is required.";
    }
}

function decrypts($data = "", $key = NULL, $salt = "")
{
    if ($key != NULL && $data != "" && $salt != "") {
        $dataEncypted = hex2bin($data);
        $method = "AES-256-CBC";

        //Converting Array to bytes
        $iv = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
        $chars = array_map("chr", $iv);
        $IVbytes = join($chars);

        $salt1 = mb_convert_encoding($salt, "UTF-8"); //Encoding to UTF-8
        $key1 = mb_convert_encoding($key, "UTF-8"); //Encoding to UTF-8

        //SecretKeyFactory Instance of PBKDF2WithHmacSHA1 Java Equivalent
        $hash = openssl_pbkdf2($key1, $salt1, '256', '65536', 'sha1');

        $decrypted = openssl_decrypt($dataEncypted, $method, $hash, OPENSSL_RAW_DATA, $IVbytes);
        return $decrypted;
    } else {

        return "Encrypted String to decrypt, Salt and Key is required.";
    }
}

function resizeAndConvertToWebP($image, $outputPath, $width = 800, $height = 600)
{
    try {
        // Create image resource from uploaded file
        $imageResource = imagecreatefromstring(file_get_contents($image->getRealPath()));

        if (!$imageResource) {
            throw new \Exception('Failed to create image resource');
        }

        // Get original dimensions
        $originalWidth = imagesx($imageResource);
        $originalHeight = imagesy($imageResource);

        // Calculate aspect ratio
        $originalAspect = $originalWidth / $originalHeight;
        $targetAspect = $width / $height;

        // Calculate new dimensions while maintaining aspect ratio
        if ($originalAspect > $targetAspect) {
            // Original is wider
            $newHeight = $height;
            $newWidth = (int)($height * $originalAspect);
        } else {
            // Original is taller or same
            $newWidth = $width;
            $newHeight = (int)($width / $originalAspect);
        }

        // Create new image with target dimensions
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG and GIF
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);
        $transparent = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
        imagefill($resizedImage, 0, 0, $transparent);

        // Resize the image
        imagecopyresampled(
            $resizedImage,
            $imageResource,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $originalWidth,
            $originalHeight
        );

        // Create final canvas with exact dimensions (center cropped)
        $finalImage = imagecreatetruecolor($width, $height);
        imagealphablending($finalImage, false);
        imagesavealpha($finalImage, true);
        $transparent = imagecolorallocatealpha($finalImage, 0, 0, 0, 127);
        imagefill($finalImage, 0, 0, $transparent);

        // Calculate cropping position (center)
        $x = (int)(($newWidth - $width) / 2);
        $y = (int)(($newHeight - $height) / 2);

        // Crop to exact dimensions
        imagecopy(
            $finalImage,
            $resizedImage,
            0,
            0,
            $x,
            $y,
            $width,
            $height
        );

        // Save as WebP with quality 80% (adjust as needed)
        $result = imagewebp($finalImage, $outputPath, 95);

        // Free memory
        imagedestroy($imageResource);
        imagedestroy($resizedImage);
        imagedestroy($finalImage);

        if (!$result) {
            throw new \Exception('Failed to save WebP image');
        }

        return true;
    } catch (\Exception $e) {
        // Fallback: move original file if WebP conversion fails
        $filename = pathinfo($outputPath, PATHINFO_FILENAME) . '.' . $image->getClientOriginalExtension();
        $fallbackPath = pathinfo($outputPath, PATHINFO_DIRNAME) . '/' . $filename;
        $image->move(pathinfo($outputPath, PATHINFO_DIRNAME), $filename);

        // You might want to log this error for debugging
        // \Log::error('WebP conversion failed: ' . $e->getMessage());

        return false;
    }
}

function PGPRODURL()
{
    $urlArray = array(
        'CASHFREE_API_VERSION' => '2025-01-01',
        'CASHFREE_CLIENTID' => '',
        'CASHFREE_CLIENTSECRET' => '',
        'CASHFREE_URL' => 'https://api.cashfree.com/pg',
        'EASEBUZZ_URL' => 'https://pay.easebuzz.in',
        'ATOM_URL' => 'https://payment1.atomtech.in/ots/aipay/auth',

    );
    return $urlArray;
}
function PGTESTURL()
{
    $urlArray = array(
        'CASHFREE_API_VERSION' => '2025-01-01',
        'CASHFREE_CLIENTID' => env('TEST_CASHFREE_CLIENTID'),
        'CASHFREE_CLIENTSECRET' => env('TEST_CASHFREE_CLIENTSECRET'),
        'CASHFREE_URL' => 'https://sandbox.cashfree.com/pg',
        'EASEBUZZ_URL' => 'https://testpay.easebuzz.in',
        'ATOM_URL' => 'https://caller.atomtech.in/ots/aipay/auth',

    );
    return $urlArray;
}


// Top-up wallet balance
function topUpWallet($validated)
{
    $validator = Validator::make($validated, [
        // 'customer_id' => 'required|integer|exists:users,id',
        'customer_id' => [
            'required',
            'integer',
            'exists:users,id',
            Rule::exists('users', 'id')->where(function ($query) use ($validated) {
                return $query->where('AgencyID', $validated['AgencyID']);
                // return $query->where('AgencyID', $validated['AgencyID'])->where('WalletStatus', 1);
            }),
        ],
        'AgencyID' => 'required|integer|exists:users,id',
        'UserSysId' => 'required|integer|exists:users,id',
        'amount' => 'required|numeric|min:0.01',
        'PaymentMode' => 'required|string|max:200',
        'RefrenceNo' => 'required|string|max:200',
        'Notes' => 'nullable|string|max:500',
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
        return [
            'status' => [
                'success' => false,
                'httpStatus' => 422,
            ],
            'message' => implode(',', $errorArray),
            'error' => $validator->messages(),
        ];
    }
    $UserData = (object) [
        'AgencyID' => $validated['AgencyID'],
        'id' => $validated['UserSysId'],
        'UserType' => 0
    ];
    $creditTransaction = CreditTransaction::where('AgencyID', $validated['AgencyID'])->where('customer_id', $validated['customer_id'])
        ->where(function ($query) {
            $query->where('Status', 'PENDING')->orWhere('Status', 'OVERDUE')->orWhere('Status', 'PARTIAL');
        })->get();

    $TotalPendingAmount = 0;

    if ($validated['amount'] > 0) {
        return DB::transaction(function () use ($validated, $creditTransaction, $UserData) {
            // Create wallet entry (credit)
            $BalanceAmount = calculateNewBalanceTopup($validated['AgencyID'], $validated['customer_id'], $validated['amount'], 'CR');
            $wallet = WalletModel::create([
                'AgencyID' => $validated['AgencyID'],
                'UserSysId' => $validated['UserSysId'],
                'customer_id' => $validated['customer_id'],
                'PType' => 'CR',
                'amount' => $validated['amount'],
                'RefrenceNo' => $validated['RefrenceNo'],
                'BalanceAmount' => $BalanceAmount,
                'Remark' => $validated['Remark'],
                'TrxId' => isset($validated['TrxID']) ? $validated['TrxID'] : '',
                'TDS' => isset($validated['TDS']) ? $validated['TDS'] : 0,
                'is_profit' => isset($validated['is_profit']) ? $validated['is_profit'] : 0,
                'PlanType' => $validated['PlanType'], // Miscellaneous
                'approved' => 1,
                'PaymentMode' => $validated['PaymentMode'],
                'PaymentDate' => Carbon::now(),
                'Notes' => $validated['Notes'] ?? null,
            ]);

            User::where('id', $validated['customer_id'])->update(['WalletBalance' => ($BalanceAmount)]);
            if ($creditTransaction) {
                foreach ($creditTransaction as $key => $value) {
                    $PaymentAmount = ($validated['amount'] > $value->BalanceAmount) ? ($value->BalanceAmount) : $validated['amount'];
                    $CreditData = [
                        'customer_id' => $validated['customer_id'],
                        'CreditTransactionID' => $value->id,
                        'PaymentAmount' => $PaymentAmount,
                        'PaymentMode' => $validated['PaymentMode'],
                        'ReferenceNo' => $validated['RefrenceNo'],
                        'Notes' => $validated['Remark'],
                        'credit_refund' => isset($validated['credit_refund']) ? $validated['credit_refund'] : 0,
                        "PlanType" => isset($validated['PlanType']) ? $validated['PlanType'] : 0,
                    ];
                    $response = processCreditPayment($CreditData, $UserData);
                    $validated['amount'] -= $PaymentAmount;
                    // If payment fully consumed, stop further processing
                    if ($validated['amount'] <= 0) {
                        break;
                    }
                }
            }
            return [
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'data' => $wallet,
                'message' => 'Wallet top-up successful'
            ];
        });
    } else {
        return [
            'status' => [
                'success' => true,
                'httpStatus' => 200,
            ],
            'data' => [],
            'message' => 'Wallet top-up successful. credit limit adjusted'
        ];
    }
}

function calculateNewBalanceTopup($AgencyID, $customer_id, $amount, $PType, $isCreditPayment = false, $hasCreditSystem = false)
{

    $lastEntry = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
        ->where(function ($query) {
            $query->where(function ($sub) {
                $sub->where('credit_refund', 1)
                    ->where('IsCreditPayment', true);
            })->orWhere(function ($sub) {
                $sub->where('credit_refund', '!=', 1)
                    ->where('IsCreditPayment', false);
            });
        })->where('is_profit', false)->orderBy('id', 'desc')->first();

    $currentBalance = $lastEntry ? $lastEntry->BalanceAmount : 0;
    // Only adjust balance if this is NOT a credit system transaction
    if (!$isCreditPayment && !$hasCreditSystem) {
        return $PType === 'CR'
            ? $currentBalance + $amount
            : $currentBalance - $amount;
    }
    // For credit system transactions, return the last actual balance
    return $currentBalance;
}


function processCreditPayment($validated, $user)
{
    try {
        $validator = Validator::make($validated, [
            'customer_id' => 'required|integer|exists:users,id',
            'CreditTransactionID' => 'required|integer|exists:credittransactions,id',
            'PaymentAmount' => 'required|numeric|min:0.01',
            'PaymentMode' => 'required|string|max:100',
            'ReferenceNo' => 'required|string|max:200',
            'Notes' => 'nullable|string|max:500',
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
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 422,
                ],
                'message' => implode(',', $errorArray),
                'error' => $validator->messages(),
            ];
        }
        // $validated = $request->all();
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        $UserSysId = $user->id;
        $validated['AgencyID'] = $AgencyID;
        $validated['UserSysId'] = $UserSysId;

        return DB::transaction(function () use ($validated) {
            // Get credit transaction
            $creditTransaction = CreditTransaction::where('id', $validated['CreditTransactionID'])
                ->where('AgencyID', $validated['AgencyID'])->where('customer_id', $validated['customer_id'])
                ->firstOrFail();
            // Validate payment amount
            if ($validated['PaymentAmount'] > $creditTransaction->BalanceAmount) {
                return [
                    'status' => [
                        'success' => false,
                        'httpStatus' => 400,
                    ],
                    'message' => 'Payment amount exceeds outstanding balance'
                ];
            }

            // Create payment history record
            $payment = CreditPaymentHistory::create([
                'CreditTransactionID' => $creditTransaction->id,
                'PaymentAmount' => $validated['PaymentAmount'],
                'PaymentDate' => Carbon::now(),
                'PaymentMode' => $validated['PaymentMode'],
                'ReferenceNo' => $validated['ReferenceNo'],
                'Notes' => $validated['Notes'] ?? null,
            ]);

            // Update credit transaction
            $creditTransaction->increment('PaidAmount', $validated['PaymentAmount']);
            $creditTransaction->decrement('BalanceAmount', $validated['PaymentAmount']);

            // Update status
            $newStatus = $creditTransaction->BalanceAmount > 0 ? 'PARTIAL' : 'PAID';
            $creditTransaction->update(['Status' => $newStatus]);

            // Update wallet (debit entry)
            $wallet = WalletModel::create([
                'AgencyID' => $validated['AgencyID'],
                'UserSysId' => $validated['UserSysId'],
                'customer_id' => $validated['customer_id'],
                'CreditSystemID' => $creditTransaction->id,
                'PType' => 'DR',
                'PaymentMode' => $validated['PaymentMode'],
                'amount' => $validated['PaymentAmount'],
                'RefrenceNo' => $validated['ReferenceNo'],
                'credit_refund' => isset($validated['credit_refund']) ? $validated['credit_refund'] : 0,
                'BalanceAmount' => calculateNewBalance($validated['AgencyID'], $validated['customer_id'], $validated['PaymentAmount'], 'CR'),
                'Remark' => 'Payment adjustment credit booking ' . $creditTransaction->ReferenceID,
                'PlanType' => isset($validated['PlanType']) ? $validated['PlanType'] : 0,
                'approved' => 1,
                'PaymentDate' => Carbon::now(),
                'IsCreditPayment' => true,
            ]);

            // Update outstanding amount in credit limit
            $creditLimit = AgentCreditLimit::where('id', $creditTransaction->CreditSystemID)
                // ->where('IsActive', true)
                ->firstOrFail();

            $creditLimit->decrement('CurrentOutstanding', $validated['PaymentAmount']);

            return [
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'data' => [
                    'Payment' => $payment,
                    'CreditTransaction' => $creditTransaction,
                    'WalletEntry' => $wallet,
                ],
                'message' => 'Payment processed successfully'
            ];
        });
    } catch (\Throwable $th) {
        return [
            'status' => [
                'success' => false,
                'httpStatus' => 500,
            ],
            'message' => $th->getMessage(),
        ];
    }
}

function calculateNewBalance($AgencyID, $customer_id, $amount, $PType, $isCreditPayment = false, $hasCreditSystem = false)
{
    $lastEntry = WalletModel::where('AgencyID', $AgencyID)->where('customer_id', $customer_id)
        ->whereNotNull('CreditSystemID')->where('is_profit', false)->orderBy('id', 'desc')->first();

    $currentBalance = $lastEntry ? $lastEntry->BalanceAmount : 0;
    // Only adjust balance if this is NOT a credit system transaction
    if (!$isCreditPayment && !$hasCreditSystem) {
        return $PType === 'CR'
            ? $currentBalance + $amount
            : $currentBalance - $amount;
    }
    // For credit system transactions, return the last actual balance
    return $currentBalance;
}


function HttpRequest($URL, $request_array, $AgencyID, $tokens = null, $method = "POST")
{
    // $APIAuthenticate = Helper::APIAuthenticate($AgencyID);
    $request_data = json_encode($request_array);
    $curl         = curl_init();
    curl_setopt($curl, CURLOPT_URL, $URL);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    // curl_setopt($curl, CURLOPT_TIMEOUT, 0);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $request_data);
    if (!empty($tokens)) {
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization:Bearer ' . $tokens,
        ));
    } else {
        $AgencyDetails = Users::getAgencyDetail($AgencyID);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization:Bearer ' . $AgencyDetails->api_key,
        ));
    }


    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        return array('curl_error' => curl_error($curl));
    }
    curl_close($curl);
    return $response;
}
