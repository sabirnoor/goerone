<?php

namespace App\Services;

use App\Models\User;
use App\Models\PanRequest;
use App\Services\BillingService;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendPasswordMail;
use Illuminate\Support\Facades\Storage;

class PanService
{
    protected $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    public function panVerify(User $user, array $data)
    {
        $dataArray = [];
        try {
            $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;

            // $SandboxAuthenticate = Helper::SandboxAuthenticate($user->id);
            // $tokenId = isset($SandboxAuthenticate['tokenId']) ? $SandboxAuthenticate['tokenId'] : '';
                $SandboxPanVerify = SurepassPanVerify($data);

                $response = isset($SandboxPanVerify['response']) ? $SandboxPanVerify['response'] : [];
                $httpCode = isset($SandboxPanVerify['httpCode']) ? $SandboxPanVerify['httpCode'] : '';
                $success = isset($response['success']) ? $response['success'] : false;
                $name_as_per_pan = isset($response['data']['full_name']) ? $response['data']['full_name'] : '';
                $date_of_birth = isset($response['data']['dob']) ? $response['data']['dob'] : null;
                $pan_number = isset($response['data']['pan_number']) ? $response['data']['pan_number'] : '';
                Storage::disk('public')->put('logs/panverify/' . $data['pan'] . '/response.json', json_encode($SandboxPanVerify));
                if ($httpCode == 200 && $success == 1) {
                    $dataArray = [
                        'user_id' => $AgencyID,
                        'pan' => $pan_number,
                        'name_as_per_pan' => $name_as_per_pan,
                        'date_of_birth' => $date_of_birth,
                        'reason' => 'Onboard user verification',
                        'status' => 'success',
                        'response' => json_encode($SandboxPanVerify),
                    ];

                    $panRequest = new PanRequest($dataArray);
                    $user->panRequests()->save($panRequest);
                    $this->billingService->chargeUser($user, 'pan', 1, $panRequest);
                    $dataArray['status'] = true;
                    return $dataArray;
                } else {
                    $dataArray['status'] = false;
                    return $dataArray;
                }
        } catch (\Exception $e) {
            // $panRequest->delete();
            $dataArray['status'] = false;
            return $dataArray;
            // throw $e;
        }
    }
}
