<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\TwilioService;
use App\Services\OtpService;
use App\Services\EmailService;
use Illuminate\Support\Facades\Validator;
use App\Models\incorporation_details;

class MessageController extends Controller
{

    public $APP_URL;
    public $APP_NAME;
    protected $twilio;
    protected $otpService;
    protected $emailService;

    public function __construct(
        OtpService $otpService,
        EmailService $emailService,
        TwilioService $twilio
    ) {
        $this->APP_URL = env('APP_URL');
        $this->APP_NAME = env('APP_NAME');
        $this->twilio = $twilio;
        $this->otpService = $otpService;
        $this->emailService = $emailService;
        $this->twilio = $twilio;
    }


    public function sendOTP(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|integer',
            'purpose' => 'required|string'
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
                    'httpStatus' => 422,
                ],
                'message' => implode(',', $errorArray),
                'error' => $validator->messages(),
            ]);
        }
        $signature = substr($request->user()->name, 0, 8);
        $user = $request->user();
        $ipAddress = $request->ip();
        $OTPS = random_int(100000, 999999);
        $AgencyID = ($request->user()->UserType == 1) ? $request->user()->id : $request->user()->AgencyID;
        try {
            if ($AgencyID == 97 && $request->user()->UserType == 1) {
                $to_email = 'goertripv2@outlook.com';
            } else {
                $to_email = $user->email;
            }

            $data = [
                'OTPS' => $OTPS,
                'logo' => GetLogo($user),
            ];
            $subject = 'OTP for User Account Verification';
            $ipAddress = $request->ip();
            $body = view('emails.otp_send', $data)->render();
            
            // $emailRequest = $this->emailService->sendEmail(
            //     $user,
            //     $to_email,
            //     $subject,
            //     $body,
            //     $ipAddress
            // );


            $to = $request->phone; // recipient number
            $message = "Dear User, your OTP for CRM access is " . $OTPS . ". DO NOT SHARE the OTP. If you did not request an OTP contact us on our support number. Team CRMOZC";
            $otpRequest = $this->otpService->generateOtp(
                $user,
                $request->phone,
                $request->purpose,
                $ipAddress,
                $OTPS,
                $message
            );
            //$message = "Your OTP for user validation is " . $OTPS . ".  \nValid for 5 minutes. Do not share it with anyone.\n" . $signature . "\n";
            $response = $this->twilio->sendSms($to, $message);
            // pr($response);
            // die;
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'OTP generated successfully',
                'request_id' => $otpRequest->id,
                // Don't return OTP in production, send via SMS instead
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => [
                    'success' => false,
                    'httpStatus' => 502,
                ],
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function verifyOtp(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'otp' => 'required|digits:6',
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
                    'httpStatus' => 422,
                ],
                'message' => implode(',', $errorArray),
                'error' => $validator->messages(),
            ]);
        }
        $user = $request->user();
        $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;

        $verified = $this->otpService->verifyOtp(
            $user,
            $request->phone,
            $request->otp
        );

        if ($verified) {
            if ($user->UserType == 2 || $user->UserType == 0) {
                $Insert['IsMobileVerify'] = 1;
                $Insert['updated_at'] = date('Y-m-d H:i:s');
                incorporation_details::where('UserSysId', $user->id)->where('AgencyID', $AgencyID)->update($Insert);
            }
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'OTP verified successfully',
            ]);
        }

        return response()->json([
            'status' => [
                'success' => false,
                'httpStatus' => 400,
            ],
            'message' => 'Invalid OTP or mobile number',
        ]);
    }
}
