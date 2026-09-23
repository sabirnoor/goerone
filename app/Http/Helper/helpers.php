<?php

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
