<?php

namespace App\Services;

use App\Models\User;
use App\Models\EmailRequest;
use App\Services\BillingService;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendPasswordMail;
use Illuminate\Support\Facades\Http;

class EmailService
{
    protected $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    public function sendEmail(User $user, string $toEmail, string $subject, string $body, string $ipAddress, $newPassword = null, $userUpdate = null)
    {
        $emailRequest = new EmailRequest([
            'to_email' => $toEmail,
            'subject' => $subject,
            'body' => $body,
            'ip_address' => $ipAddress,
        ]);

        $user->emailRequests()->save($emailRequest);

        try {
            // Charge the user for email
            $this->billingService->chargeUser($user, 'email', 1, $emailRequest);

            // Send the email
            if ($newPassword) {
                // Mail::to($userUpdate->email)->send(new SendPasswordMail($userUpdate->id, $newPassword, false));
                $html = (new SendPasswordMail($userUpdate->id, $newPassword, false))->render();
                $this->sendViaElasticApi($user, $userUpdate->email, 'Your Password', $html);
                ///Mail::to($userUpdate->email)->send((new SendPasswordMail($userUpdate->id, $newPassword, false))->from(env('MAIL_FROM_ADDRESS'), $user->name));
            } else {
                // Mail::raw($body, function ($message) use ($toEmail, $subject) {
                //     $message->to($toEmail)
                //         ->subject($subject);
                // });
                $this->sendViaElasticApi($user, $toEmail, $subject, $body);
                // Mail::html($body, function ($message) use ($toEmail, $subject, $user) {
                //     $message->to($toEmail)->from(env('MAIL_FROM_ADDRESS'), $user->name)
                //         ->subject($subject);
                // });
            }
            $emailRequest->update([
                'is_sent' => true,
                'sent_at' => now(),
            ]);

            return $emailRequest;
        } catch (\Exception $e) {
            $emailRequest->delete();
            return response()->json([
                'success' => false,
                'status' => [
                    'success' => false,
                    'httpStatus' => 5002,
                ],
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send one email through Elastic Email API v4 using the client's own key.
     * Throws on failure so the existing try/catch in sendEmail() handles it.
     */
    protected function sendViaElasticApi(User $user, string $toEmail, string $subject, string $html): void
    {
        if (empty($user->emailkey)) {
            throw new \Exception('Elastic Email API key is not configured for this user.');
        }

        // Must be a sender/domain verified under this client's Elastic account
        $fromAddress = 'sabir@ozilconnect.com'; //env('MAIL_FROM_ADDRESS'); // TODO: swap for the client's own verified sender
        $fromName    = str_replace(['<', '>', '"'], '', $user->details->agencyName);

        $response = Http::withHeaders([
            'X-ElasticEmail-ApiKey' => $user->emailkey,
        ])
            ->timeout(30)
            ->acceptJson()
            ->post('https://api.elasticemail.com/v4/emails/transactional', [
                'Recipients' => [
                    'To' => [$toEmail],
                ],
                'Content' => [
                    'From'    => "{$fromName} <{$fromAddress}>",
                    'Subject' => $subject,
                    'ReplyTo' => $fromAddress,
                    'Body'    => [
                        [
                            'ContentType' => 'HTML',
                            'Content'     => $html,
                            'Charset'     => 'utf-8',
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            $error = $response->json('Error') ?? $response->body();
            throw new \Exception('Email API error: ' . $error);
        }
    }
}
