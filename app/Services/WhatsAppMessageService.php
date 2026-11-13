<?php

namespace App\Services;

use App\Events\MessageCreated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Users;
use App\Models\WhatsappAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\WhatsAppMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class WhatsAppMessageService
{
    protected $apiVersion;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiVersion = config('services.meta.api_version', 'v24.0');
        $this->baseUrl    = "https://graph.facebook.com/{$this->apiVersion}";
    }


    /* =========================================
     | Send Text Message
     ========================================= */
    public function sendText(Conversation $conversation, string $text, $user = null, ?string $replyToMessageId = null, ?String $senderType = null, $isFeedback = false): Message
    {
        if (!$conversation->isWindowOpen()) {
            $conversation->update(['status' => 'closed']);
            throw new \Exception('24-hour window expired');
        }
        // if ($conversation->status !== 'open') {
        //     throw new \Exception('24-hour window expired');
        // }

        $account        = $conversation->whatsappAccount;
        // $appsecretProof = $this->generateAppsecretProof($account->user_access_token);
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $textPayload = [
            'messaging_product' => 'whatsapp',
            // 'appsecret_proof'   => $appsecretProof,
            'to'                => $conversation->contact->wa_id,
            'type'              => 'text',
            'text'              => ['body' => $text],
        ];

        // ✅ Add reply context ONLY if exists
        if (!empty($replyToMessageId)) {
            $textPayload['context'] = [
                'message_id' => $replyToMessageId
            ];
        }

        $sendUrl = "{$this->baseUrl}/{$account->phone_number_id}/messages";

        // Log::info('[WA Message] ── SEND REQUEST (text) ──', [
        //     'method'  => 'POST',
        //     'url'     => $sendUrl,
        //     'payload' => array_merge($textPayload, ['appsecret_proof' => '***redacted***']),
        // ]);

        // $response = Http::withToken($account->user_access_token)
        //     ->post($sendUrl, $textPayload);
        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->post($sendUrl, $textPayload);

        // if (!$response->successful()) {
        //     Log::error('[WA Message] \u2717 sendText FAILED', [
        //         'httpStatus' => $response->status(),
        //         'to'         => $conversation->contact->wa_id,
        //         'error'      => $response->json('error'),
        //     ]);
        //     throw new \Exception('Message send failed');
        // }
        // 🔁 Retry once if failed
        if (!$response->successful()) {

            Log::warning('⚠️ sendText failed — retrying once', [
                'conversation_id' => $conversation->id,
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            // small delay (important for race conditions)
            usleep(300000); // 300ms

            // retry
            // $response = Http::withToken($account->user_access_token)
            //     ->post($sendUrl, $textPayload);
            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->post($sendUrl, $textPayload);
        }

        // ❌ Still failed after retry
        if (!$response->successful()) {

            Log::error('[WA Message] ❌ sendText FAILED after retry', [
                'httpStatus' => $response->status(),
                'to'         => $conversation->contact->wa_id,
                'error'      => $response->json('error'),
            ]);

            throw new \Exception('Message send failed after retry');
        }

        if ($isFeedback) {
            return new Message([
                'conversation_id' => $conversation->id,
                'type' => 'text',
                'body' => $text,
                'status' => 'sent',
            ]);
        }

        $message = Message::create([
            'agency_id'           => $conversation->agency_id,
            'UserSysId'           => (!empty($user) && $user->id > 0) ? $user->id : null,
            'conversation_id'     => $conversation->id,
            'direction'           => 'out',
            'type'                => 'text',
            'body'                => $text,
            'sender_type'   => $senderType,
            'whatsapp_message_id' => $response['messages'][0]['id'] ?? null,
            'status'              => 'sent',
            'whatsapp_message_reply_id'    => $replyToMessageId,
        ]);

        event(new MessageCreated($message));
        return $message;
    }

    /* =========================================
     | Send Media Message
     ========================================= */
    public function sendMedia(
        Conversation $conversation,
        UploadedFile $file,
        string $type,
        ?string $caption = null,
        $user = null,
        ?string $replyToMessageId = null
    ): Message {
        if (!$conversation->isWindowOpen()) {
            $conversation->update(['status' => 'closed']);
            throw new \Exception('24-hour window expired');
        }

        $account = $conversation->whatsappAccount;

        // Log::info('[WA Message] sendMedia called', [
        //     'conversation_id' => $conversation->id,
        //     'type'            => $type,
        //     'fileName'        => $file->getClientOriginalName(),
        //     'phpMime'         => $file->getMimeType(),
        //     'size'            => $file->getSize(),
        // ]);

        // FIX: pass explicit $type so WhatsAppMediaService does not misdetect
        // Safari audio/mp4 (reported as video/mp4 by PHP) as a video file.
        $mediaId = app(WhatsAppMediaService::class)->upload(
            $file,
            $account->user_access_token,
            $account->phone_number_id,
            $type   // ← explicit type overrides PHP mime detection
        );

        $appsecretProof = $this->generateAppsecretProof($account->user_access_token);

        // getMeta() only takes 2 params — appsecretProof is generated internally
        $meta = app(WhatsAppMediaService::class)->getMeta($mediaId, $account->user_access_token);

        // ── Build send payload ───────────────────────────────────────────────
        if ($type === 'audio') {
            // 'voice' => true makes WhatsApp render it as a voice note waveform,
            // not a generic audio file attachment
            $payload = [
                'messaging_product' => 'whatsapp',
                // 'appsecret_proof'   => $appsecretProof,
                'to'                => $conversation->contact->wa_id,
                'type'              => 'audio',
                'audio'             => [
                    'id'    => $mediaId,
                    'voice' => true,
                ],
            ];
        } else {
            $payload = [
                'messaging_product' => 'whatsapp',
                // 'appsecret_proof'   => $appsecretProof,
                'to'                => $conversation->contact->wa_id,
                'type'              => $type,
                $type               => ['id' => $mediaId],
            ];
        }

        if ($caption && in_array($type, ['image', 'video', 'document'])) {
            $payload[$type]['caption'] = $caption;
        }

        // ✅ Add filename ONLY for document
        if ($type === 'document' && isset($file)) {
            $payload['document']['filename'] = $file->getClientOriginalName();
        }

        // ✅ Add reply context ONLY if exists
        if (!empty($replyToMessageId)) {
            $payload['context'] = [
                'message_id' => $replyToMessageId
            ];
        }

        $sendUrl = "https://graph.facebook.com/v24.0/{$account->phone_number_id}/messages";

        // $response = Http::withToken($account->user_access_token)
        //     ->post($sendUrl, $payload);
        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->post($sendUrl, $payload);
        // ── Log full send response — this is where delivery failures show up ──
        // Log::info('[WA Message] WhatsApp send response', [
        //     'httpStatus'       => $response->status(),
        //     'waMessageId'      => $response->json('messages.0.id'),
        //     'errorCode'        => $response->json('error.code'),
        //     'errorMessage'     => $response->json('error.message'),
        //     'errorSubcode'     => $response->json('error.error_subcode'),
        //     'errorUserMsg'     => $response->json('error.error_user_msg'),
        //     'rawBody'          => $response->body(),
        // ]);

        if (!$response->successful()) {
            Log::error('[WA Message] ✗ Media send FAILED', [
                'type'     => $type,
                'mediaId'  => $mediaId,
                'to'       => $conversation->contact->wa_id,
                'error'    => $response->json('error'),
                'httpStatus' => $response->status(),
            ]);
            throw new \Exception(
                $response->json('error.message') ?? 'Media send failed'
            );
        }

        $message = Message::create([
            'agency_id'           => $conversation->agency_id,
            'UserSysId'           => (!empty($user) && $user->id > 0) ? $user->id : null,
            'conversation_id'     => $conversation->id,
            'direction'           => 'out',
            'type'                => $type,
            'body'                => $caption,
            'whatsapp_message_id' => $response->json('messages.0.id'),
            'media_id'            => $mediaId,
            'media_url'           => $meta['url'] ?? null,
            'media_file_name'     => $file->getClientOriginalName(),
            'media_mime_type'     => $type === 'audio' ? 'audio/mpeg' : $file->getMimeType(),
            'status'              => 'sent',
            'whatsapp_message_reply_id'    => $replyToMessageId,
        ]);

        event(new MessageCreated($message));
        return $message;
    }

    /* =========================================
     | Mark Message as Read
     ========================================= */
    public static function markAsRead(WhatsappAccount $account, string $messageId): void
    {
        $appsecretProof = self::generateAppsecretProofStatic($account->user_access_token);
        Http::withToken($account->user_access_token)
            ->post(
                "https://graph.facebook.com/v24.0/{$account->phone_number_id}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'appsecret_proof'   => $appsecretProof,
                    'status'            => 'read',
                    'message_id'        => $messageId,
                ]
            );
    }

    public function sendTicket($account, $payload, $user = null)
    {
        try {
            // ── Generate appsecret proof ─────────────────────────────
            $appsecretProof = $this->generateAppsecretProof(
                $account->user_access_token,
                $user
            );

            // ── API Request ─────────────────────────────────────────
            $url = "{$this->baseUrl}/{$account->phone_number_id}/messages";

            $startTime = microtime(true);

            $response = Http::withToken($account->user_access_token)
                ->timeout(30)
                ->post($url, array_merge($payload, [
                    'appsecret_proof' => $appsecretProof,
                ]));

            $duration = round((microtime(true) - $startTime) * 1000, 2);
        } catch (\Throwable $e) {
            Log::error('sendTicket exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_id' => $account->id ?? null,
            ]);

            throw new \Exception('WhatsApp request failed');
        }

        // ── Handle API failure ──────────────────────────────────────
        if (!$response->successful()) {
        $errorBody = $response->json();

        Log::error('[WA Message] sendTicket failed', [
            'status' => $response->status(),
            'response' => $errorBody,
            'account_id' => $account->id ?? null,
        ]);

        // Exception::__construct requires a string, not an array —
        // json_encode it so the real WhatsApp API error message survives
        // into $e->getMessage() (and from there into error_message in
        // whatsapp_notification_logs).
        throw new \Exception(
            is_array($errorBody) ? json_encode($errorBody) : (string) $errorBody
        );
        }
        // ── Success Logs ────────────────────────────────────────────
        $responseBody = $response->json();

        return $responseBody;
    }
    /* =========================================
     | Fetch Message Templates
     ========================================= */
    public function getTemplates($account, $user = null)
    {
        $appsecretProof = $this->generateAppsecretProof($account->user_access_token, $user);
        $response = Http::withToken($account->user_access_token)
            ->get("{$this->baseUrl}/{$account->waba_id}/message_templates", [
                'limit' => 100,
                'appsecret_proof' => $appsecretProof,
            ]);

        if (!$response->successful()) {
            Log::error('[WA Message] getTemplates failed', $response->json());
            throw new \Exception('Failed to fetch templates');
        }

        return $response->json();
    }

    /* =========================================
     | appsecret_proof helpers
     ========================================= */
    private function generateAppsecretProof(string $accessToken, $user = null): string
    {
        try {
            $agencyId = ($user?->UserType == 1) ? $user->id : $user?->AgencyID;

            if (!empty($user?->details?->META_APP_SECRET)) {
                $appSecret = $user->details->META_APP_SECRET;
            } else {
                $appSecret = ($agencyId ? Users::getAgencyDetail($agencyId)->META_APP_SECRET ?? null : null)?? meta_app_secret();
            }

            if (empty($appSecret)) {
                Log::error('❌ Meta App Secret EMPTY');
                throw new \Exception('Meta App Secret is not configured');
            }

            $proof = hash_hmac('sha256', $accessToken, $appSecret);

            return $proof;
        } catch (\Throwable $e) {

            Log::error('❌ generateAppsecretProof FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public static function generateAppsecretProofStatic(string $accessToken): string
    {
        $appSecret = meta_app_secret();

        if (empty($appSecret)) {
            throw new \Exception('Meta App Secret is not configured');
        }

        return hash_hmac('sha256', $accessToken, $appSecret);
    }

    public function blockContact(Conversation $conversation, $userId, ?string $reason = null)
    {
        $contact = $conversation->contact;
        $account = $conversation->whatsappAccount;

        $token = $account->user_access_token;

        // 🔐 Generate appsecret_proof
        $appsecretProof = $this->generateAppsecretProof($token);

        $url = "{$this->baseUrl}/{$account->phone_number_id}/block_users";

        $payload = [
            'messaging_product' => 'whatsapp',
            'block_users' => [
                ['user' => $contact->wa_id]
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('[WA Block] Failed', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                throw new \Exception('WhatsApp block failed');
            }
        } catch (\Throwable $e) {
            Log::error('[WA Block] Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        // 💾 DB UPDATE
        $contact->update([
            'is_blocked'     => true,
            'blocked_by'     => $userId,
            'blocked_reason' => $reason,
            'blocked_at'     => now(),
        ]);

        return $contact->refresh();
    }

    public function unblockContact(Conversation $conversation)
    {
        $contact = $conversation->contact;
        $account = $conversation->whatsappAccount;

        $token = $account->user_access_token;

        // 🔐 Generate proof
        $appsecretProof = $this->generateAppsecretProof($token);

        $url = "{$this->baseUrl}/{$account->phone_number_id}/block_users";

        $payload = [
            'messaging_product' => 'whatsapp',
            'block_users' => [
                ['user' => $contact->wa_id]
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->withBody(json_encode($payload), 'application/json')
                ->delete($url);

            if (!$response->successful()) {
                Log::error('[WA Unblock - DELETE] Failed', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                throw new \Exception('WhatsApp unblock failed');
            }
        } catch (\Throwable $e) {
            Log::error('[WA Unblock - DELETE] Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        // 💾 DB UPDATE
        $contact->update([
            'is_blocked'     => false,
            'blocked_by'     => null,
            'blocked_reason' => null,
            'blocked_at'     => null,
        ]);

        return $contact->refresh();
    }

    //To send Interactive messages using Lists
    public function sendInteractiveList(
        Conversation $conversation,
        string $header,
        string $body,
        array $sections,
        $user = null
    ): Message {

        if (!$conversation->isWindowOpen()) {
            Log::warning('⛔ 24-hour window closed', [
                'conversation_id' => $conversation->id
            ]);
            $conversation->update(['status' => 'closed']);
            throw new \Exception('24-hour window expired');
        }

        $account = $conversation->whatsappAccount;

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            // 'appsecret_proof'   => $appsecretProof,
            "recipient_type"    => 'individual',
            'to'                => $conversation->contact->wa_id,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'list',
                'header' => [
                    'type' => 'text',
                    'text' => $header
                ],
                'body' => [
                    'text' => $body
                ],
                'action' => [
                    'button' => 'Select Option',
                    'sections' => $sections
                ]
            ]
        ];

        $sendUrl = "{$this->baseUrl}/{$account->phone_number_id}/messages";

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->post($sendUrl, $payload);

        if (!$response->successful()) {
            Log::error('❌ sendInteractiveList FAILED', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            throw new \Exception('Interactive list send failed');
        }

        $message = Message::create([
            'agency_id' => $conversation->agency_id,
            'UserSysId' => (!empty($user) && $user->id > 0) ? $user->id : null,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'interactive',
            'body' => $body,
            'interactive_payload' => json_encode($payload['interactive']),
            'sender_type'         => 'bot',
            'whatsapp_message_id' => $response['messages'][0]['id'] ?? null,
            'status' => 'sent',
        ]);

        return $message;
    }
    //To send Interactive messages using buttons
    public function sendInteractiveButtons(
        Conversation $conversation,
        string $body,
        array $buttons,
        $user = null,
    ): Message {
        if (!$conversation->isWindowOpen()) {
            throw new \Exception('24-hour window expired');
        }

        $account = $conversation->whatsappAccount;

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            "recipient_type"    => 'individual',
            'to'                => $conversation->contact->wa_id,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => [
                    'text' => $body
                ],
                'action' => [
                    'buttons' => $buttons
                ]
            ]
        ];

        $sendUrl = "{$this->baseUrl}/{$account->phone_number_id}/messages";

        try {

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->post($sendUrl, $payload);

            if (!$response->successful()) {
                Log::error('❌ [WA] Interactive Button Failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                throw new \Exception('Interactive button send failed');
            }

            return Message::create([
                'agency_id'           => $conversation->agency_id,
                'UserSysId'           => (!empty($user) && $user->id > 0) ? $user->id : null,
                'conversation_id'     => $conversation->id,
                'direction'           => 'out',
                'type'                => 'interactive',
                'body'                => $body,
                'interactive_payload' => json_encode($payload['interactive']),
                'sender_type'         => 'bot',
                'whatsapp_message_id' => $response['messages'][0]['id'] ?? null,
                'status'              => 'sent',
            ]);
        } catch (\Exception $e) {
            // ✅ EXCEPTION LOG (NETWORK / TIMEOUT / UNKNOWN)
            Log::error('🔥 [WA] Exception in sendInteractiveButtons', [
                'error' => $e->getMessage(),
                'conversation_id' => $conversation->id,
            ]);

            throw $e;
        }
    }

    public function sendInteractiveURL(
        Conversation $conversation,
        string $header,
        string $body,
        String $url,
        String $buttonLabel,
        $user = null
    ): Message {

        if (!$conversation->isWindowOpen()) {
            Log::warning('⛔ 24-hour window closed', [
                'conversation_id' => $conversation->id
            ]);
            $conversation->update(['status' => 'closed']);
            throw new \Exception('24-hour window expired');
        }

        $account = $conversation->whatsappAccount;

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $conversation->contact->wa_id,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'cta_url',
                'header' => [
                    'type' => 'text',
                    'text' => $header
                ],
                'body'   => [
                    'text' => $body
                ],
                'action' => [
                    'name'       => 'cta_url',
                    'parameters' => [
                        'display_text' => $buttonLabel,
                        'url'          => $url
                    ]
                ]
            ]
        ];

        $sendUrl = "{$this->baseUrl}/{$account->phone_number_id}/messages";

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->post($sendUrl, $payload);

        if (!$response->successful()) {
            Log::error('❌ sendInteractiveurl FAILED', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            throw new \Exception('Interactive url send failed');
        }

        $message = Message::create([
            'agency_id' => $conversation->agency_id,
            'UserSysId' => (!empty($user) && $user->id > 0) ? $user->id : null,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'interactive',
            'body' => $body,
            'interactive_payload' => json_encode($payload['interactive']),
            'sender_type'         => 'bot',
            'whatsapp_message_id' => $response['messages'][0]['id'] ?? null,
            'status' => 'sent',
        ]);

        return $message;
    }

    //To send Interactive messages using flows
    public function sendInteractiveFlow(
        Conversation $conversation,
        string $body,
        string $flowId,
        string $flowCta = 'Proceed',
        String $header,
        $user = null,
        $isFeedback = false
    ): Message {

        if (!$conversation->isWindowOpen()) {
            Log::warning('⛔ 24-hour window closed', [
                'conversation_id' => $conversation->id
            ]);
            $conversation->update(['status' => 'closed']);
            throw new \Exception('24-hour window expired');
        }

        $account = $conversation->whatsappAccount;

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $conversation->contact->wa_id,
            'type'              => 'interactive',
            'interactive' => [
                'type' => 'flow',
                'header' => [
                    'type' => 'text',
                    'text' => $header
                ],
                'body' => [
                    'text' => $body
                ],
                'footer' => [
                    'text' => 'Takes 10 seconds'
                ],
                'action' => [
                    'name' => 'flow',
                    'parameters' => [
                        'flow_message_version' => '3',
                        'flow_name' => $flowId,
                        'flow_cta' => $flowCta,
                        'flow_token' => (string) Str::uuid(),
                        // 'flow_action' => 'navigate',
                        // 'flow_action_payload' => [
                        //     'screen' => 'TRAVEL_FORM',
                        //     'data' => [
                        //         'conversation_id' => $conversation->id,
                        //         'user_id' => $user?->id,
                        //     ]
                        // ]
                    ]
                ]
            ]
        ];

        $sendUrl = "{$this->baseUrl}/{$account->phone_number_id}/messages";

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->post($sendUrl, $payload);

        if (!$response->successful()) {
            Log::error('❌ sendFlow FAILED', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            throw new \Exception('Flow send failed');
        }

        if ($isFeedback) {
            return new Message([
                'conversation_id' => $conversation->id,
                'type' => 'flow',
                'body' => $body,
                'status' => 'sent',
            ]);
        }


        $message = Message::create([
            'agency_id' => $conversation->agency_id,
            'UserSysId' => (!empty($user) && $user->id > 0) ? $user->id : null,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'flow',
            'body' => $body,
            'interactive_payload' => json_encode($payload['interactive']),
            'sender_type'         => 'bot',
            'whatsapp_message_id' => $response['messages'][0]['id'] ?? null,
            'status' => 'sent',
        ]);

        return $message;
    }

    /**
     * List all flows for a WhatsApp Business Account (WABA).
     * GET /{waba_id}/flows
     */
    public function getFlows(WhatsappAccount $account, $user = null): array
    {
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$account->waba_id}/flows";

        $query = [
            'fields' => 'id,name,status,categories,validation_errors,preview',
            'appsecret_proof' => $appsecretProof,
        ];

        $response = Http::withToken($account->user_access_token)
            ->get($url, $query);

        // Retry once
        if (!$response->successful()) {

            Log::warning('⚠️ getFlows failed — retrying once', [
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->get($url, $query);
        }

        if (!$response->successful()) {

            Log::error('❌ [WA Flows] getFlows FAILED after retry', [
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to fetch flows'
            );
        }

        return $response->json();
    }

    /**
     * Get a single flow by ID.
     * GET /{flow_id}?fields=id,name,status,categories,validation_errors,preview,json_version,data_api_version
     */
    public function getFlow(WhatsappAccount $account, $user = null, string $flowId): array
    {
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$flowId}";

        $query = [
            'fields' => implode(',', [
                'id',
                'name',
                'status',
                'categories',
                'validation_errors',
                'preview',
                'json_version',
                'data_api_version',
                'application'
            ]),
            'appsecret_proof' => $appsecretProof,
        ];

        $response = Http::withToken($account->user_access_token)
            ->get($url, $query);

        // Retry once
        if (!$response->successful()) {

            Log::warning('⚠️ getFlow failed — retrying once', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->get($url, $query);
        }

        // Still failed
        if (!$response->successful()) {

            Log::error('❌ [WA Flows] getFlow FAILED after retry', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to fetch flow'
            );
        }

        return $response->json();
    }

    public function getFlowAssets(
        WhatsappAccount $account,
        $user = null,
        string $flowId
    ): array {

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$flowId}/assets";

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->get($url);

        // Retry once
        if (!$response->successful()) {

            Log::warning('⚠️ getFlowAssets failed — retrying once', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->get($url);
        }

        if (!$response->successful()) {

            Log::error('❌ [WA Flows] getFlowAssets FAILED after retry', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to fetch flow assets'
            );
        }

        return $response->json();
    }

    public function downloadFlowJsonAsset(
        WhatsappAccount $account,
        $user = null,
        string $flowId
    ): ?array {
        // Fetch assets
        $assetsResponse = $this->getFlowAssets(
            $account,
            $user,
            $flowId
        );

        $assets = $assetsResponse['data'] ?? [];

        foreach ($assets as $asset) {

            if (
                ($asset['name'] ?? '') === 'flow.json'
                || ($asset['asset_type'] ?? '') === 'FLOW_JSON'
            ) {

                $downloadUrl = $asset['download_url'] ?? null;

                if (!$downloadUrl) {
                    continue;
                }

                $response = Http::get($downloadUrl);

                // Retry once
                if (!$response->successful()) {

                    Log::warning('⚠️ flow JSON download failed — retrying once', [
                        'flow_id' => $flowId,
                        'status'  => $response->status(),
                        'error'   => $response->json('error'),
                    ]);

                    usleep(300000);

                    $response = Http::get($downloadUrl);
                }

                if (!$response->successful()) {

                    Log::error('❌ [WA Flows] Flow JSON download FAILED', [
                        'flow_id' => $flowId,
                        'status'  => $response->status(),
                        'error'   => $response->json('error'),
                    ]);

                    throw new \Exception(
                        $response->json('error.message')
                            ?? 'Failed to download flow JSON'
                    );
                }

                return $response->json();
            }
        }

        return null;
    }
    /**
     * Create a new flow.
     * POST /{waba_id}/flows
     */
    public function createFlow(
        WhatsappAccount $account,
        $user = null,
        string $name,
        array $categories = ['OTHER']
    ): array {

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$account->waba_id}/flows";

        $payload = [
            'name'       => $name,
            'categories' => $categories,
        ];

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->post($url, $payload);

        // Retry once
        if (!$response->successful()) {

            Log::warning('⚠️ createFlow failed — retrying once', [
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->post($url, $payload);
        }

        // Still failed
        if (!$response->successful()) {

            Log::error('❌ [WA Flows] createFlow FAILED after retry', [
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to create flow'
            );
        }

        return $response->json();
    }

    /**
     * Update flow JSON assets.
     * POST /{flow_id}/assets   (multipart — sends the flow JSON as a file upload)
     */
    public function updateFlowJson(
        WhatsappAccount $account,
        $user = null,
        string $flowId,
        array $flowJson
    ): array {
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$flowId}/assets";

        $jsonString = json_encode(
            $this->castEmptyArraysToObjects($flowJson),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->attach(
                'file',
                $jsonString,
                'flow.json',
                ['Content-Type' => 'application/json']
            )
            ->post($url, [
                'asset_type' => 'FLOW_JSON',
                'name'       => 'flow.json',
            ]);

        // Retry once
        if (!$response->successful()) {

            Log::warning('⚠️ updateFlowJson failed — retrying once', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->attach(
                    'file',
                    $jsonString,
                    'flow.json',
                    ['Content-Type' => 'application/json']
                )
                ->post($url, [
                    'asset_type' => 'FLOW_JSON',
                    'name'       => 'flow.json',
                ]);
        }

        // Still failed
        if (!$response->successful()) {

            Log::error('❌ [WA Flows] updateFlowJson FAILED after retry', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to update flow JSON'
            );
        }

        return $response->json();
    }

    private function castEmptyArraysToObjects(mixed $value): mixed
    {
        if (is_array($value)) {
            if (empty($value)) {
                return new \stdClass(); // {} not []
            }
            // Check if it's associative (object) or sequential (array)
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                $obj = new \stdClass();
                foreach ($value as $k => $v) {
                    $obj->$k = $this->castEmptyArraysToObjects($v);
                }
                return $obj;
            }
            // Sequential array — recurse into items but keep as array
            return array_map(fn($item) => $this->castEmptyArraysToObjects($item), $value);
        }
        return $value;
    }
    /**
     * Publish a flow (DRAFT → PUBLISHED).
     * POST /{flow_id}/publish
     */
    public function publishFlow(WhatsappAccount $account, $user = null, string $flowId): array
    {
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$flowId}/publish";

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->post($url);

        // Retry once
        if (!$response->successful()) {

            Log::warning('⚠️ publishFlow failed — retrying once', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->post($url);
        }

        // Still failed
        if (!$response->successful()) {

            Log::error('❌ [WA Flows] publishFlow FAILED after retry', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to publish flow'
            );
        }
        return $response->json();
    }

    /**
     * Deprecate a published flow (PUBLISHED → DEPRECATED).
     * POST /{flow_id}/deprecate
     */
    public function deprecateFlow(WhatsappAccount $account, $user = null, string $flowId): array
    {
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$flowId}/deprecate";

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->post($url);

        // Retry once
        if (!$response->successful()) {

            Log::warning('⚠️ deprecateFlow failed — retrying once', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->post($url);
        }

        // Still failed
        if (!$response->successful()) {

            Log::error('❌ [WA Flows] deprecateFlow FAILED after retry', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to deprecate flow'
            );
        }

        return $response->json();
    }

    /**
     * Delete a DRAFT flow permanently.
     * DELETE /{flow_id}
     */
    public function deleteFlow(WhatsappAccount $account, $user = null, string $flowId): array
    {

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$flowId}";

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => [
                    'appsecret_proof' => $appsecretProof
                ]
            ])
            ->delete($url);

        // Retry once
        if (!$response->successful()) {

            Log::warning('⚠️ deleteFlow failed — retrying once', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => [
                        'appsecret_proof' => $appsecretProof
                    ]
                ])
                ->delete($url);
        }

        // Still failed
        if (!$response->successful()) {

            Log::error('❌ [WA Flows] deleteFlow FAILED after retry', [
                'flow_id' => $flowId,
                'status'  => $response->status(),
                'error'   => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to delete flow'
            );
        }

        return $response->json();
    }
    /**
     * Clone a flow — creates a new DRAFT flow with same name + copies JSON assets.
     * Meta has no native clone endpoint so we:
     *  1. Fetch the source flow's JSON asset
     *  2. Create a new flow with same name (appended " (Copy)")
     *  3. Upload the JSON to the new flow
     */
    public function cloneFlow(
        WhatsappAccount $account,
        $user = null,
        string $flowId
    ): array {

        // ── Step 1: Fetch source flow details ──────────────────────────────
        $source = $this->getFlow(
            $account,
            $user,
            $flowId
        );

        $sourceName = ($source['name'] ?? 'Flow') . ' (Copy)';

        $categories = $source['categories'] ?? ['OTHER'];

        // ── Step 2: Download source flow JSON ─────────────────────
        $flowJson = $this->downloadFlowJsonAsset(
            $account,
            $user,
            $flowId
        );

        // ── Step 3: Create new flow ────────────────────────────────────────
        $newFlow = $this->createFlow(
            $account,
            $user,
            $sourceName,
            $categories
        );

        $newFlowId = $newFlow['id'] ?? null;

        if (!$newFlowId) {
            throw new \Exception('Failed to create cloned flow');
        }

        // ── Step 5: Upload JSON to cloned flow ─────────────────────────────
        if ($flowJson) {

            $this->updateFlowJson(
                $account,
                $user,
                $newFlowId,
                $flowJson
            );
        }

        return [
            'id'             => $newFlowId,
            'name'           => $sourceName,
            'source_flow_id' => $flowId,
        ];
    }

    /**
     * Get flow preview URL (for "Run" / preview mode).
     * Returns the preview_url from the flow object.
     */
    public function getFlowPreviewUrl(WhatsappAccount $account, $user = null, string $flowId): ?string
    {
        try {

            $flow = $this->getFlow(
                $account,
                $user,
                $flowId
            );

            $previewUrl = $flow['preview']['preview_url'] ?? null;

            return $previewUrl;
        } catch (\Throwable $e) {

            Log::error('❌ [WA Flows] getFlowPreviewUrl failed', [
                'flow_id' => $flowId,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update flow name and/or categories.
     * POST /{flow_id}   (Graph API — standard form fields, not assets)
     */
    public function updateFlowMeta(
        WhatsappAccount $account,
        $user = null,
        string $flowId,
        ?string $name = null,
        ?array $categories = null
    ): array {

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $url = "{$this->baseUrl}/{$flowId}";

        $payload = array_filter([
            'name'       => $name,
            'categories' => $categories,
        ], fn($v) => $v !== null);

        $response = Http::withToken($account->user_access_token)
            ->withOptions(['query' => ['appsecret_proof' => $appsecretProof]])
            ->post($url, $payload);

        if (!$response->successful()) {
            Log::warning('⚠️ updateFlowMeta failed — retrying once', [
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            usleep(300000);

            $response = Http::withToken($account->user_access_token)
                ->withOptions(['query' => ['appsecret_proof' => $appsecretProof]])
                ->post($url, $payload);
        }

        if (!$response->successful()) {
            Log::error('❌ [WA Flows] updateFlowMeta FAILED after retry', [
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            throw new \Exception(
                $response->json('error.message') ?? 'Failed to update flow metadata'
            );
        }

        return $response->json();
    }

    /* =========================================
     | Create Message Template
     | POST /{waba_id}/message_templates
     |
     | Supports all component types:
     |   Header  : TEXT | IMAGE | VIDEO | DOCUMENT
     |   Body    : text + variable examples
     |   Footer  : plain text
     |   Buttons : QUICK_REPLY | URL | PHONE_NUMBER
     ========================================= */
    public function createTemplate(
        $account,
        $user = null,
        array $params
    ): array {

        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        // ── 1. Build components array ──────────────────────────────────────
        $components = [];

        // ── Header ────────────────────────────────────────────────────────
        $headerType = strtoupper($params['header_type'] ?? 'NONE');

        if ($headerType === 'TEXT' && !empty($params['header_text'])) {
            $headerComponent = [
                'type'   => 'HEADER',
                'format' => 'TEXT',
                'text'   => $params['header_text'],
            ];

            // Header text variable example (if {{1}} present)
            if (str_contains($params['header_text'], '{{1}}') && !empty($params['header_text_example'])) {
                $headerComponent['example'] = [
                    'header_text' => [$params['header_text_example']],
                ];
            }

            $components[] = $headerComponent;
        }

        if (in_array($headerType, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
            // header_handle comes from uploading the media via the Resumable Upload API first.
            // The controller handles the upload and passes the handle here.
            $headerComponent = [
                'type'   => 'HEADER',
                'format' => $headerType,
            ];

            // if (!empty($params['header_handle'])) {
            //     $headerComponent['example'] = [
            //         'header_handle' => [$params['header_handle']],
            //     ];
            // }
            if (!empty($params['header_handle'])) {
                $headerComponent['example'] = [
                    'header_handle' => [$params['header_handle']],
                ];
            } elseif (!empty($params['header_link'])) {
                $headerComponent['example'] = [
                    'header_handle' => [$params['header_link']],
                ];
            }
            $components[] = $headerComponent;
        }

        // ── Body ──────────────────────────────────────────────────────────

        if (strtoupper($params['category']) === 'AUTHENTICATION') {
            // Meta requires a fixed BODY for auth templates — no custom text allowed
            $components[] = [
                'type'                          => 'BODY',
                'add_security_recommendation'   => true,  // adds "For your security, do not share this code."
            ];
        } elseif (!empty($params['body_text'])) {
            $bodyComponent = [
                'type' => 'BODY',
                'text' => $params['body_text'],
            ];

            $bodyExamples = $params['body_examples'] ?? [];
            if (!empty($bodyExamples)) {
                ksort($bodyExamples);
                $bodyComponent['example'] = [
                    'body_text' => [array_values($bodyExamples)],
                ];
            }

            $components[] = $bodyComponent;
        }

        // ── Footer ────────────────────────────────────────────────────────
        if (strtoupper($params['category']) === 'AUTHENTICATION') {
            // Fixed auth footer — tells user how long the code is valid
            $components[] = [
                'type'                  => 'FOOTER',
                'code_expiration_minutes' => 10,
            ];
        } elseif (!empty($params['footer_text'])) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $params['footer_text'],
            ];
        }

        // ── Buttons ───────────────────────────────────────────────────────
        $buttons = $params['buttons'] ?? [];
        if (!empty($buttons)) {
            $builtButtons = [];

            foreach ($buttons as $i => $btn) {

                $type = strtoupper($btn['type'] ?? 'QUICK_REPLY');

                // ── COPY CODE (AUTHENTICATION ONLY) ─────────────────
                if (
                    $type === 'COPY_CODE' &&
                    strtoupper($params['category']) === 'AUTHENTICATION'
                ) {

                    $builtButtons[] = [
                        'type'     => 'OTP',
                        'otp_type' => 'COPY_CODE',
                        'text'     => $btn['code_label'] ?? 'Copy Code',
                    ];

                    continue;
                }

                // ── FLOW BUTTON ─────────────────────────────────────────
                if ($type === 'FLOW') {
                    $flowAction = $btn['flow_action'] ?? 'navigate';

                    $flowButton = [
                        'type'        => 'FLOW',
                        'text'        => $btn['text'],
                        'flow_id'     => $btn['flow_id'],
                        'flow_action' => $flowAction,
                    ];

                    if ($flowAction === 'navigate') {
                        if (empty($btn['navigate_screen'])) {
                            throw new \Exception(
                                'navigate_screen is required when flow_action is navigate.'
                            );
                        }
                        $flowButton['navigate_screen'] = $btn['navigate_screen'];
                    }

                    if ($flowAction === 'data_exchange' && !empty($btn['flow_token'])) {
                        $flowButton['flow_token'] = $btn['flow_token'];
                    }

                    $builtButtons[] = $flowButton;
                    continue;
                }

                // ── Standard buttons ────────────────────────────────
                $builtBtn = [
                    'type' => $type,
                    'text' => $btn['text'],
                ];

                if ($type === 'URL') {

                    $builtBtn['url'] = $btn['url'];

                    // URL variable example
                    if (
                        str_contains($btn['url'], '{{1}}') &&
                        !empty($btn['url_example'])
                    ) {
                        $builtBtn['example'] = [$btn['url_example']];
                    }
                }

                if ($type === 'PHONE_NUMBER') {
                    $builtBtn['phone_number'] = $btn['phone'];
                }

                $builtButtons[] = $builtBtn;
            }
            $components[] = [
                'type'    => 'BUTTONS',
                'buttons' => $builtButtons,
            ];
        }

        // ── 2. Build request payload ────────────────────────────────────
        $payload = [
            'name'       => $params['name'],
            'category'   => strtoupper($params['category']),
            'language'   => $params['language'],
            'components' => $components,
        ];
        // Log::info('📤 TEMPLATE PAYLOAD', [
        //     'payload' => $payload
        // ]);
        $url = "{$this->baseUrl}/{$account->waba_id}/message_templates";

        // ── 3. Send request ────────────────────────────────────────────
        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => ['appsecret_proof' => $appsecretProof],
            ])
            ->post($url, $payload);

        // ── 4. Retry once on failure ───────────────────────────────────
        if (!$response->successful()) {
            Log::warning('⚠️ createTemplate failed — retrying once', [
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            usleep(300000); // 300 ms

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => ['appsecret_proof' => $appsecretProof],
                ])
                ->post($url, $payload);
        }

        // ── 5. Throw on persistent failure ────────────────────────────
        if (!$response->successful()) {
            Log::error('❌ [WA Template] createTemplate FAILED after retry', [
                'httpStatus'   => $response->status(),
                'errorMessage' => $response->json('error.message'),
                'rawBody'      => $response->body(),
            ]);

            throw new \Exception(
                $response->json('error.message')
                    ?? 'Failed to create template'
            );
        }

        return $response->json();
    }

    /* =========================================
     | Upload Template Header Media
     |
     | Uses Meta's Resumable Upload API to get
     | a media handle for IMAGE/VIDEO/DOCUMENT
     | headers before template creation.
     |
     | Returns the upload handle string.
     ========================================= */
    public function uploadTemplateHeaderMedia(
        $account,
        $user = null,
        UploadedFile $file,
        string $mediaType
    ): string {
        // ─────────────────────────────────────────────────────────────
        // Generate appsecret proof
        // ─────────────────────────────────────────────────────────────
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $metaAppId = null;

        if ($user->UserType !== 1) {
            $AgencyID = ($user->UserType == 1) ? $user->id : $user->AgencyID;
            $AgencyDetails = Users::getAgencyDetail($AgencyID);
            $metaAppId = $AgencyDetails->META_APP_ID ?? null;
        } else {
            $metaAppId = $user->details->META_APP_ID ?? null;
        }
        // ─────────────────────────────────────────────────────────────
        // Validate App ID
        // ─────────────────────────────────────────────────────────────
        if (empty($metaAppId)) {
            Log::error('❌ [WA Template] Missing Meta App ID', [
                'metaAppId type1' => $metaAppId,
            ]);
            throw new \Exception('Meta App ID is missing');
        }

        // ─────────────────────────────────────────────────────────────
        // STEP 1: Start Upload Session
        // ─────────────────────────────────────────────────────────────
        $startUrl = "https://graph.facebook.com/v24.0/{$metaAppId}/uploads";

        try {

            $startResponse = Http::withToken($account->user_access_token)
                ->timeout(120)
                ->acceptJson()
                ->post($startUrl, [
                    'upload_phase'   => 'start',
                    'file_name'      => $file->getClientOriginalName(),
                    'file_length'    => $file->getSize(),
                    'file_type'      => $file->getMimeType(),
                    'appsecret_proof' => $appsecretProof,
                ]);
        } catch (\Throwable $e) {

            Log::error('❌ [WA Template] Upload session request exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        if (!$startResponse->successful()) {

            Log::error('❌ [WA Template] Failed to start upload session', [
                'status' => $startResponse->status(),
                'body'   => $startResponse->body(),
                'json'   => $startResponse->json(),
            ]);

            throw new \Exception(
                $startResponse->json('error.message')
                    ?? 'Failed to start upload session'
            );
        }

        $uploadSessionId = $startResponse->json('id');

        if (!$uploadSessionId) {

            Log::error('❌ [WA Template] Upload session ID missing', [
                'response' => $startResponse->json(),
            ]);

            throw new \Exception('Upload session ID not returned from Meta');
        }

        // ─────────────────────────────────────────────────────────────
        // STEP 2: Upload File Bytes
        // ─────────────────────────────────────────────────────────────
        $uploadUrl = "https://graph.facebook.com/v24.0/{$uploadSessionId}";

        try {

            $binary = file_get_contents($file->getRealPath());

            $uploadResponse = Http::withToken($account->user_access_token)
                ->timeout(300)
                ->withHeaders([
                    'file_offset' => '0',
                ])
                ->withBody(
                    $binary,
                    'application/octet-stream'
                )
                ->post($uploadUrl, [
                    'appsecret_proof' => $appsecretProof,
                ]);
        } catch (\Throwable $e) {

            Log::error('❌ [WA Template] Upload bytes exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        if (!$uploadResponse->successful()) {

            Log::error('❌ [WA Template] File upload failed', [
                'status' => $uploadResponse->status(),
                'body'   => $uploadResponse->body(),
                'json'   => $uploadResponse->json(),
            ]);

            throw new \Exception(
                $uploadResponse->json('error.message')
                    ?? 'Failed to upload file bytes'
            );
        }

        $handle = $uploadResponse->json('h');

        if (!$handle) {

            Log::error('❌ [WA Template] Upload handle missing', [
                'response' => $uploadResponse->json(),
            ]);

            throw new \Exception('Upload handle not returned from Meta');
        }

        return $handle;
    }

    public function deleteTemplate($account, $user, string $templateId, string $templateName): void
    {
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => ['appsecret_proof' => $appsecretProof],
            ])
            ->delete("{$this->baseUrl}/{$account->waba_id}/message_templates", [
                'hsm_id' => $templateId,
                'name'   => $templateName,
            ]);

        if (!$response->successful()) {
            Log::error('❌ [WA Template] deleteTemplate FAILED', [
                'httpStatus'   => $response->status(),
                'errorMessage' => $response->json('error.message'),
                'rawBody'      => $response->body(),
            ]);

            throw new \Exception(
                $response->json('error.message') ?? 'Failed to delete template'
            );
        }
    }


    /* ── Helper: fetch a template's current status from Meta ─────────────────── */
    public function getTemplateStatus($account, string $templateId): string
    {
        $appsecretProof = $this->generateAppsecretProof($account->user_access_token);

        $response = Http::withToken($account->user_access_token)
            ->withOptions(['query' => ['appsecret_proof' => $appsecretProof]])
            ->get("{$this->baseUrl}/{$templateId}", ['fields' => 'status']);

        if (!$response->successful()) {
            throw new \Exception($response->json('error.message') ?? 'Failed to fetch template status.');
        }

        return $response->json('status') ?? 'UNKNOWN';
    }

    /* ── Helper: fetch a template's current category from Meta ───────────────── */
    public function getTemplateCategory($account, string $templateId): string
    {
        $appsecretProof = $this->generateAppsecretProof($account->user_access_token);

        $response = Http::withToken($account->user_access_token)
            ->withOptions(['query' => ['appsecret_proof' => $appsecretProof]])
            ->get("{$this->baseUrl}/{$templateId}", ['fields' => 'category']);

        if (!$response->successful()) {
            throw new \Exception($response->json('error.message') ?? 'Failed to fetch template category.');
        }

        return strtoupper($response->json('category') ?? 'MARKETING');
    }

    /* ── Helper: fetch a template's current buttons from Meta ─────────────────── */
    public function getTemplateButtons($account, string $templateId): array
    {
        $appsecretProof = $this->generateAppsecretProof($account->user_access_token);

        $response = Http::withToken($account->user_access_token)
            ->withOptions(['query' => ['appsecret_proof' => $appsecretProof]])
            ->get("{$this->baseUrl}/{$templateId}", ['fields' => 'components']);

        if (!$response->successful()) {
            throw new \Exception($response->json('error.message') ?? 'Failed to fetch template components.');
        }

        $components = $response->json('components') ?? [];
        foreach ($components as $component) {
            if (strtoupper($component['type'] ?? '') === 'BUTTONS') {
                return $component['buttons'] ?? [];
            }
        }

        return [];
    }


    public function updateTemplate(
        $account,
        $user = null,
        array $params,
        string $category   // must be fetched from Meta, not from $params
    ): array {
        $appsecretProof = $this->generateAppsecretProof(
            $account->user_access_token,
            $user
        );

        $isAuth = strtoupper($category) === 'AUTHENTICATION';

        // ── Build components ──────────────────────────────────────────────────────
        $components = [];

        // ── Header ────────────────────────────────────────────────────────────────
        $headerType = strtoupper($params['header_type'] ?? 'NONE');

        if (!$isAuth && $headerType === 'TEXT' && !empty($params['header_text'])) {
            $headerComponent = [
                'type'   => 'HEADER',
                'format' => 'TEXT',
                'text'   => $params['header_text'],
            ];
            if (str_contains($params['header_text'], '{{1}}') && !empty($params['header_text_example'])) {
                $headerComponent['example'] = ['header_text' => [$params['header_text_example']]];
            }
            $components[] = $headerComponent;
        }

        if (!$isAuth && in_array($headerType, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
            $headerComponent = [
                'type'   => 'HEADER',
                'format' => $headerType,
            ];
            if (!empty($params['header_handle'])) {
                $headerComponent['example'] = ['header_handle' => [$params['header_handle']]];
            }
            $components[] = $headerComponent;
        }

        // ── Body ──────────────────────────────────────────────────────────────────
        if ($isAuth) {
            $components[] = [
                'type'                        => 'BODY',
                'add_security_recommendation' => true,
            ];
        } elseif (!empty($params['body_text'])) {
            $bodyComponent = [
                'type' => 'BODY',
                'text' => $params['body_text'],
            ];
            $bodyExamples = $params['body_examples'] ?? [];
            if (!empty($bodyExamples)) {
                ksort($bodyExamples);
                $bodyComponent['example'] = ['body_text' => [array_values($bodyExamples)]];
            }
            $components[] = $bodyComponent;
        }

        // ── Footer ────────────────────────────────────────────────────────────────
        if ($isAuth) {
            $components[] = [
                'type'                    => 'FOOTER',
                'code_expiration_minutes' => 10,
            ];
        } elseif (!empty($params['footer_text'])) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $params['footer_text'],
            ];
        }

        // ── Buttons ───────────────────────────────────────────────────────────────
        $buttons = $params['buttons'] ?? [];
        if (!empty($buttons)) {
            $builtButtons = [];

            foreach ($buttons as $btn) {
                $type = strtoupper($btn['type'] ?? 'QUICK_REPLY');

                // COPY_CODE (AUTH only)
                if ($type === 'COPY_CODE' && $isAuth) {
                    $builtButtons[] = [
                        'type'     => 'OTP',
                        'otp_type' => 'COPY_CODE',
                        'text'     => $btn['code_label'] ?? 'Copy Code',
                    ];
                    continue;
                }

                // FLOW button
                if ($type === 'FLOW') {
                    $flowAction  = $btn['flow_action'] ?? 'navigate';
                    $flowButton  = [
                        'type'        => 'FLOW',
                        'text'        => $btn['text'],
                        'flow_id'     => $btn['flow_id'],
                        'flow_action' => $flowAction,
                    ];
                    if ($flowAction === 'navigate') {
                        if (empty($btn['navigate_screen'])) {
                            throw new \Exception('navigate_screen is required when flow_action is navigate.');
                        }
                        $flowButton['navigate_screen'] = $btn['navigate_screen'];
                    }
                    if ($flowAction === 'data_exchange' && !empty($btn['flow_token'])) {
                        $flowButton['flow_token'] = $btn['flow_token'];
                    }
                    $builtButtons[] = $flowButton;
                    continue;
                }

                // Standard buttons
                $builtBtn = [
                    'type' => $type,
                    'text' => $btn['text'],
                ];
                if ($type === 'URL') {
                    $builtBtn['url'] = $btn['url'];
                    if (str_contains($btn['url'], '{{1}}') && !empty($btn['url_example'])) {
                        $builtBtn['example'] = [$btn['url_example']];
                    }
                }
                if ($type === 'PHONE_NUMBER') {
                    $builtBtn['phone_number'] = $btn['phone'];
                }

                $builtButtons[] = $builtBtn;
            }

            $components[] = [
                'type'    => 'BUTTONS',
                'buttons' => $builtButtons,
            ];
        }

        // ── Build payload ─────────────────────────────────────────────────────────
        // NOTE: `name`, `category`, `language` are intentionally omitted — Meta
        // uses the template ID to find the existing record and only updates
        // the components we send.
        $payload = ['components' => $components];

        $url = "{$this->baseUrl}/{$params['template_id']}";

        // ── Send request ──────────────────────────────────────────────────────────
        $response = Http::withToken($account->user_access_token)
            ->withOptions([
                'query' => ['appsecret_proof' => $appsecretProof],
            ])
            ->post($url, $payload);

        // ── Retry once on failure ─────────────────────────────────────────────────
        if (!$response->successful()) {
            Log::warning('⚠️ updateTemplate failed — retrying once', [
                'status' => $response->status(),
                'error'  => $response->json('error'),
            ]);

            usleep(300000); // 300 ms

            $response = Http::withToken($account->user_access_token)
                ->withOptions([
                    'query' => ['appsecret_proof' => $appsecretProof],
                ])
                ->post($url, $payload);
        }

        // ── Throw on persistent failure ───────────────────────────────────────────
        if (!$response->successful()) {
            Log::error('❌ [WA Template] updateTemplate FAILED after retry', [
                'httpStatus'   => $response->status(),
                'errorMessage' => $response->json('error.message'),
                'rawBody'      => $response->body(),
            ]);

            throw new \Exception(
                $response->json('error.message') ?? 'Failed to update template'
            );
        }

        return $response->json();
    }
}