<?php

namespace App\Services;

use App\Models\User;
use App\Models\OtpRequest;
use App\Services\BillingService;
use Illuminate\Support\Str;
use App\Services\TwilioService;

class OtpService
{
    protected $billingService;
    protected $twilio;

    public function __construct(BillingService $billingService, TwilioService $twilio)
    {
        $this->billingService = $billingService;
        $this->twilio = $twilio;
    }

    public function generateOtp(User $user, string $mobileNumber, string $purpose, string $ipAddress, string $OTPS, string $message)
    {
        $otp = $OTPS; //Str::random(6); // or use rand(100000, 999999) for numeric OTP

        $otpRequest = new OtpRequest([
            'mobile_number' => $mobileNumber,
            'otp' => $otp,
            'purpose' => $purpose,
            'ip_address' => $ipAddress,
        ]);

        $user->otpRequests()->save($otpRequest);

        try {
            $length = mb_strlen($message);
            $length = ($length > 160) ? 2 : 1;
            // Charge the user for OTP
            $this->billingService->chargeUser($user, 'sms', $length, $otpRequest);

            // Here you would typically send the OTP via SMS gateway
            // $this->sendSms($mobileNumber, "Your OTP is: $otp");

            return $otpRequest;
        } catch (\Exception $e) {
            $otpRequest->delete();
            throw $e;
        }
    }
    public function generateOtpNew(User $user, string $mobileNumber, string $purpose, string $ipAddress, string $OTPS)
    {
        $otp = $OTPS; //Str::random(6); // or use rand(100000, 999999) for numeric OTP
        $otpRequest = new OtpRequest([
            'mobile_number' => $mobileNumber,
            'otp' => $otp,
            'purpose' => $purpose,
            'ip_address' => $ipAddress,
        ]);

        $user->otpRequests()->save($otpRequest);

        $to = $mobileNumber; // recipient number

        try {
            // Charge the user for OTP
            $this->billingService->chargeUser($user, 'sms', 1, $otpRequest);
            $this->twilio->sendSms($to, $purpose);
            // Here you would typically send the OTP via SMS gateway
            // $this->sendSms($mobileNumber, "Your OTP is: $otp");

            return [
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'OTP generated successfully',
                'otpRequestID' => $otpRequest->id,
            ];
        } catch (\Exception $e) {
            $otpRequest->delete();
            return [
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $e->getMessage(),
            ];
            throw $e;
        }
    }

    public function verifyOtp(User $user, string $mobileNumber, string $otp)
    {
        $otpRequest = OtpRequest::where('user_id', $user->id)
            ->where('mobile_number', $mobileNumber)
            ->where('otp', $otp)
            ->where('is_verified', false)
            ->first();

        if (!$otpRequest) {
            return false;
        }

        $otpRequest->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        return true;
    }
}
