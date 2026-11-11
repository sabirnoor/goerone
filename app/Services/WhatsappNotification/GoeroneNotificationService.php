<?php

namespace App\Services\WhatsappNotification;

use App\Http\Helper\CampaignPayloadBuilder;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappNotificationLog;
use App\Services\WhatsAppMessageService;
use App\Services\WhatsappNotification\Contracts\WhatsappVarMapper;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Generic WhatsApp template notification sender for GoerOne.
 *
 * ── Easiest way: one line, driven by config/whatsapp_notifications.php ─────────
 *
 *   GoeroneNotificationService::notify('withdrawal_request', $user, $data, reference: $withdrawalId);
 *
 *   // only some recipients
 *   GoeroneNotificationService::notify('withdrawal_request', $user, $data, only: ['customer']);
 *
 * ── Fluent way: when you need full control ─────────────────────────────────────
 *
 *   GoeroneNotificationService::forUser($user, $reference)
 *       ->withData($data)
 *       ->toCustomer(MembershipPurchasedCustomerMapper::class)
 *       ->toStaff(MembershipPurchasedStaffMapper::class)
 *       ->loggedAs('membership', 'system')
 *       ->sendSafely();
 *
 * $data must contain 'customer_phone' (and 'staff_phone' for staff recipients,
 * or set WA_NOTIFY_STAFF_PHONE). Everything else in $data is passed straight to
 * the mapper's map().
 *
 * Debug logging: every line that starts with `$this->debug(` is removable and is
 * gated by WA_NOTIFY_DEBUG. Warnings/errors are always logged.
 * Grep the log for "[WA-Notify]".
 */
class GoeroneNotificationService
{
    private User    $user;
    private int     $agencyId;

    /** Your own id for what this is about (withdrawal id, order id, membership id …) */
    private ?string $referenceId = null;

    private string  $sourceType = 'goerone_notification';
    private string  $sendType   = 'system';
    private ?int    $senderId   = null;

    private ?string $eventName   = null;
    /** ['customer' => Mapper::class, 'staff' => Mapper::class] loaded from config by event() */
    private array   $eventMappers = [];

    /** Fallback mapper used by recipients that have none of their own */
    private ?string $mapperClass  = null;
    private ?string $templateName = null;

    /** 'static' | 'custom' | 'none' | null (= let each mapper decide) */
    private ?string  $headerMode           = null;
    private ?string  $staticHeaderUrl      = null;
    private ?string  $staticHeaderFilename = null;
    private ?Closure $headerResolver       = null;
    private ?array   $resolvedHeader       = null;

    private ?WhatsappAccount $account = null;

    /** Each: ['phone' => ?string, 'type' => 'customer'|'staff'|'manual', 'mapperClass' => ?string] */
    private array $recipients = [];

    /** Data forwarded to mapper::map() */
    private array $data = [];

    // ─────────────────────────────────────────────────────────────────────────
    // Entry points
    // ─────────────────────────────────────────────────────────────────────────

    private function __construct() {}

    /**
     * One-liner: send every recipient configured for an event.
     * Never throws — failures are logged and returned in the NotificationResult.
     *
     * @param string[]|null $only  e.g. ['customer'] to skip staff. null = all recipients of the event.
     */
    public static function notify(
        string $event,
        User $user,
        array $data = [],
        string|int|null $reference = null,
        ?array $only = null,
    ): NotificationResult {
        try {
            $service = self::forUser($user, $reference)->event($event)->withData($data);

            foreach (array_keys($service->eventMappers) as $type) {
                if ($only !== null && !in_array($type, $only, true)) {
                    continue;
                }
                $service->addPlaceholderRecipient($type, null);
            }

            return $service->sendSafely();
        } catch (\Throwable $e) {
            Log::error('[WA-Notify] notify() failed before sending', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            return new NotificationResult(0, 1, ['*' => $e->getMessage()]);
        }
    }

    public static function forUser(User $user, string|int|null $reference = null): self
    {
        $instance              = new self();
        $instance->user        = $user;
        $instance->agencyId    = ($user->UserType == 1) ? $user->id : $user->AgencyID;
        $instance->referenceId = $reference !== null ? (string) $reference : null;
        return $instance;
    }

    /** Backward-compatible alias for the old API. */
    public static function forBooking(string|int $bookingId, User $user): self
    {
        return self::forUser($user, $bookingId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fluent configurators
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load customer/staff mappers for an event from config/whatsapp_notifications.php.
     * ->toCustomer() / ->toStaff() with no argument then use that event's mapper.
     */
    public function event(string $event): self
    {
        $config = config("whatsapp_notifications.events.{$event}");

        if (!is_array($config)) {
            throw new \InvalidArgumentException(
                "WhatsApp event '{$event}' is not defined in config/whatsapp_notifications.php"
            );
        }

        $this->eventName    = $event;
        $this->eventMappers = $config['recipients'] ?? [];
        $this->sourceType   = $config['source'] ?? $event;

        return $this;
    }

    /** Data forwarded to the mapper (plus customer_phone / staff_phone for phone lookup). */
    public function withData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /** Alias kept so old call sites keep working. */
    public function withRawData(array $data): self
    {
        return $this->withData($data);
    }

    /** Explicit fallback template + mapper for recipients that don't have their own. */
    public function template(string $templateName, string $mapperClass): self
    {
        $this->templateName = $templateName;
        $this->mapperClass  = $mapperClass;
        return $this;
    }

    /** Send to explicit phone numbers (uses the fallback mapper from ->template()). */
    public function to(string ...$phones): self
    {
        foreach ($phones as $phone) {
            $this->recipients[] = [
                'phone'       => $this->normalisePhone($phone),
                'type'        => 'manual',
                'mapperClass' => null,
            ];
        }
        return $this;
    }

    /** Customer phone comes from $data['customer_phone']. */
    public function toCustomer(?string $mapperClass = null): self
    {
        return $this->addPlaceholderRecipient('customer', $mapperClass);
    }

    /** Staff phone comes from $data['staff_phone'] or WA_NOTIFY_STAFF_PHONE. */
    public function toStaff(?string $mapperClass = null): self
    {
        return $this->addPlaceholderRecipient('staff', $mapperClass);
    }

    public function toBoth(): self
    {
        return $this->toCustomer()->toStaff();
    }

    /** Use a fixed public URL as header (IMAGE / DOCUMENT / VIDEO) for every recipient. */
    public function withHeader(string $url, ?string $filename = null): self
    {
        $this->headerMode           = 'static';
        $this->staticHeaderUrl      = $url;
        $this->staticHeaderFilename = $filename;
        return $this;
    }

    /**
     * Generate the header lazily (e.g. an invoice PDF). Closure must return [url, filename].
     * Runs once per send() and is reused for all recipients.
     *
     *   ->withHeaderUsing(fn () => [$pdfUrl, 'invoice.pdf'])
     */
    public function withHeaderUsing(Closure $resolver): self
    {
        $this->headerMode     = 'custom';
        $this->headerResolver = $resolver;
        return $this;
    }

    public function withoutHeader(): self
    {
        $this->headerMode = 'none';
        return $this;
    }

    /** Send through a specific WhatsApp account instead of the user's agency account. */
    public function usingAccount(WhatsappAccount $account): self
    {
        $this->account = $account;
        return $this;
    }

    /**
     * Tag this send for the log table.
     * $sendType: 'system' | 'human'. $senderId only matters for 'human'.
     */
    public function loggedAs(string $sourceType, string $sendType = 'system', ?int $senderId = null): self
    {
        $this->sourceType = $sourceType;
        $this->sendType   = $sendType;
        $this->senderId   = $senderId;
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Execute
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Like send() but never throws — safe to call inside a controller
     * where a WhatsApp problem must not break the main request.
     */
    public function sendSafely(): NotificationResult
    {
        try {
            return $this->send();
        } catch (\Throwable $e) {
            Log::error('[WA-Notify] send aborted', [
                'event'     => $this->eventName,
                'reference' => $this->referenceId,
                'agency_id' => $this->agencyId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return new NotificationResult(0, max(1, count($this->recipients)), ['*' => $e->getMessage()]);
        }
    }

    /**
     * Send all messages. Throws on configuration problems (no mapper, no WhatsApp
     * account); per-recipient API failures are caught and counted.
     */
    public function send(): NotificationResult
    {
        $this->resolveRecipientMappers();
        $this->validate();

        $this->debug('start', [
            'event'      => $this->eventName,
            'source'     => $this->sourceType,
            'recipients' => array_map(fn($r) => ['type' => $r['type'], 'mapper' => $r['mapperClass']], $this->recipients),
            'data_keys'  => array_keys($this->data),
        ]);

        $this->resolvePhones();

        if (empty($this->recipients)) {
            Log::warning('[WA-Notify] Nothing sent — no recipient had a usable phone number', [
                'event'     => $this->eventName,
                'reference' => $this->referenceId,
                'data_keys' => array_keys($this->data),
            ]);
            return new NotificationResult(0, 0);
        }

        $account = $this->account ?? WhatsappAccount::where('AgencyID', $this->agencyId)->first();

        if (!$account) {
            throw new \RuntimeException("WhatsApp account not found for AgencyID {$this->agencyId}.");
        }

        $this->debug('account', ['account_id' => $account->id ?? null, 'agency_id' => $this->agencyId]);

        $whatsAppService = app(WhatsAppMessageService::class);

        $sent   = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->recipients as $recipient) {
            $phone  = $recipient['phone'];
            $type   = $recipient['type'];
            /** @var class-string<WhatsappVarMapper> $mapper */
            $mapper = $recipient['mapperClass'];

            $templateName = $recipient['templateName'] ?? $mapper::templateName();
            $langCode     = $mapper::langCode();

            $headerFormat   = null;
            $headerUrl      = null;

            try {
                [$headerFormat, $headerUrl, $headerFilename] = $this->resolveHeader($mapper);

                $varValues = $mapper::map($this->data, $type, $this->user, 'whatsapp');

                $this->debug('mapped', [
                    'type'     => $type,
                    'template' => $templateName,
                    'vars'     => $varValues,
                ]);

                $payload = CampaignPayloadBuilder::build(
                    phone: $phone,
                    templateName: $templateName,
                    varValues: $varValues,
                    langCode: $langCode,
                    headerFormat: $headerFormat,
                    headerValue: $headerUrl,
                    headerFilename: $headerFilename,
                );

                $this->debug('payload', ['to' => $phone, 'payload' => $payload]);

                $response = $whatsAppService->sendTicket($account, $payload, $this->user);

                $this->debug('response', ['to' => $phone, 'response' => $response]);

                $sent++;

                $this->log(
                    phone: $phone,
                    recipientType: $type,
                    templateName: $templateName,
                    langCode: $langCode,
                    headerFormat: $headerFormat,
                    headerUrl: $headerUrl,
                    status: 'sent',
                    response: is_array($response) ? $response : null,
                );
            } catch (\Throwable $e) {
                Log::error('[WA-Notify] Send failed', [
                    'event'     => $this->eventName,
                    'reference' => $this->referenceId,
                    'phone'     => $phone,
                    'type'      => $type,
                    'template'  => $templateName,
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);

                $errors[$phone] = $e->getMessage();
                $failed++;

                $this->log(
                    phone: $phone,
                    recipientType: $type,
                    templateName: $templateName,
                    langCode: $langCode,
                    headerFormat: $headerFormat,
                    headerUrl: $headerUrl,
                    status: 'failed',
                    errorMessage: $e->getMessage(),
                );
            }
        }

        $this->debug('done', ['sent' => $sent, 'failed' => $failed, 'errors' => $errors]);

        return new NotificationResult(sent: $sent, failed: $failed, errors: $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function addPlaceholderRecipient(string $type, ?string $mapperClass): self
    {
        $this->recipients[] = [
            'phone'       => null,
            'type'        => $type,
            'mapperClass' => $mapperClass,
        ];
        return $this;
    }

    /**
     * Give every recipient a concrete mapper:
     *   its own  →  the event's mapper for its type  →  the ->template() fallback.
     */
    private function resolveRecipientMappers(): void
    {
        foreach ($this->recipients as &$recipient) {
            if (!empty($recipient['mapperClass'])) {
                continue;
            }

            if (!empty($this->eventMappers[$recipient['type']])) {
                $recipient['mapperClass'] = $this->eventMappers[$recipient['type']];
            } else {
                $recipient['mapperClass'] = $this->mapperClass;
                $recipient['templateName'] = $this->templateName;
            }
        }
        unset($recipient);
    }

    private function validate(): void
    {
        if (empty($this->recipients)) {
            throw new \LogicException(
                'No recipients set. Use notify(), ->to($phone), ->toCustomer(), ->toStaff() or ->toBoth().'
            );
        }

        foreach ($this->recipients as $recipient) {
            $mapper = $recipient['mapperClass'] ?? null;

            if (empty($mapper)) {
                throw new \LogicException(
                    "No mapper for '{$recipient['type']}' recipient. Define it in config/whatsapp_notifications.php " .
                    "(event '{$this->eventName}'), or pass a mapper to ->toCustomer(Mapper::class) / ->toStaff(Mapper::class), " .
                    "or call ->template('name', Mapper::class)."
                );
            }

            if (!is_subclass_of($mapper, WhatsappVarMapper::class)) {
                throw new \LogicException("{$mapper} must implement " . WhatsappVarMapper::class);
            }
        }
    }

    /**
     * Replace 'customer' / 'staff' placeholders with real numbers and drop
     * recipients that have none (with a warning — this used to fail silently).
     *
     * If WA_NOTIFY_TEST_NUMBER is set, every message is redirected to it.
     */
    private function resolvePhones(): void
    {
        $testNumber = config('whatsapp_notifications.test_number');
        $resolved   = [];

        foreach ($this->recipients as $recipient) {
            $phone = $recipient['phone'] ?? null;

            if (empty($phone)) {
                $raw = match ($recipient['type']) {
                    'customer' => $this->data['customer_phone'] ?? '',
                    'staff'    => $this->data['staff_phone'] ?? config('whatsapp_notifications.staff_phone', ''),
                    'store'    => $this->data['store_phone'] ?? '',
                    default    => '',
                };
                $phone = $this->normalisePhone((string) $raw);
            }

            if (empty($phone)) {
                Log::warning('[WA-Notify] Recipient skipped — no phone number', [
                    'event'     => $this->eventName,
                    'reference' => $this->referenceId,
                    'type'      => $recipient['type'],
                    'hint'      => "Pass '{$recipient['type']}_phone' in the data array" .
                                   ($recipient['type'] === 'staff' ? ' or set WA_NOTIFY_STAFF_PHONE' : ''),
                ]);
                continue;
            }

            $recipient['original_phone'] = $phone;

            if (!empty($testNumber)) {
                $phone = $this->normalisePhone((string) $testNumber);
            }

            $recipient['phone'] = $phone;
            $resolved[]         = $recipient;
        }

        $this->recipients = $resolved;

        $this->debug('phones', array_map(
            fn($r) => ['type' => $r['type'], 'to' => $r['phone'], 'original' => $r['original_phone']],
            $this->recipients
        ));
    }

    /**
     * Work out [headerFormat, headerUrl, headerFilename] for one mapper.
     *
     * Priority: explicit ->withHeader / ->withHeaderUsing / ->withoutHeader
     *           → mapper::needsPdfHeader() → media header with mapper::staticHeaderValue().
     * TEXT headers are only sent when you explicitly call ->withHeader(), because
     * a TEXT header without {{1}} in the template must not receive a parameter.
     */
    private function resolveHeader(string $mapper): array
    {
        $mode   = $this->headerMode;
        $format = $mapper::headerFormat();

        if ($mode === null) {
            if ($mapper::needsPdfHeader()) {
                $mode = 'custom';
            } elseif (
                in_array(strtoupper((string) $format), ['IMAGE', 'DOCUMENT', 'VIDEO'], true)
                && $mapper::staticHeaderValue()
            ) {
                $mode = 'static';
            }
        }

        switch ($mode) {
            case 'none':
                return [null, null, null];

            case 'static':
                return [$format, $this->staticHeaderUrl ?? $mapper::staticHeaderValue(), $this->staticHeaderFilename];

            case 'custom':
                if ($this->headerResolver === null) {
                    throw new \LogicException(
                        "{$mapper} needs a generated header. Call ->withHeaderUsing(fn () => [\$url, \$filename])."
                    );
                }
                if ($this->resolvedHeader === null) {
                    $this->resolvedHeader = ($this->headerResolver)();
                    $this->debug('header generated', ['header' => $this->resolvedHeader]);
                }
                return [$format, $this->resolvedHeader[0] ?? null, $this->resolvedHeader[1] ?? null];

            default:
                return [$format, null, null];
        }
    }

    /** Normalise to E.164 with a leading +. 10-digit numbers are treated as Indian. */
    private function normalisePhone(string $phone): ?string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            $digits = preg_replace('/\D+/', '', substr($phone, 1));
            return $digits === '' ? null : '+' . $digits;
        }

        $phone = preg_replace('/\D+/', '', $phone);

        if ($phone === '') {
            return null;
        }

        if (strlen($phone) === 10) {
            return '+91' . $phone;
        }

        return '+' . $phone;
    }

    /** Removable debug trace. Gated by WA_NOTIFY_DEBUG. */
    private function debug(string $step, array $context = []): void
    {
        if (!config('whatsapp_notifications.debug')) {
            return;
        }

        Log::channel(config('whatsapp_notifications.log_channel'))->info(
            '[WA-Notify] ' . $step,
            ['ref' => $this->referenceId, 'agency' => $this->agencyId] + $context
        );
    }

    /** Persist the outcome in whatsapp_notification_logs. Never breaks the flow. */
    private function log(
        ?string $phone,
        string  $recipientType,
        string  $templateName,
        ?string $langCode     = null,
        ?string $headerFormat = null,
        ?string $headerUrl    = null,
        string  $status       = 'sent',
        ?array  $response     = null,
        ?string $errorMessage = null,
    ): void {
        try {
            WhatsappNotificationLog::create([
                'agency_id'        => $this->agencyId,
                'booking_id'       => $this->referenceId,
                'template_name'    => $templateName,
                'lang_code'        => $langCode,
                'phone'            => $phone,
                'recipient_type'   => $recipientType,
                'send_type'        => $this->sendType,
                'sender_id'        => $this->sendType === 'human' ? ($this->senderId ?? $this->user->id) : null,
                'source_type'      => $this->sourceType,
                'header_format'    => $headerFormat,
                'header_url'       => $headerUrl,
                'status'           => $status,
                'response_payload' => $response,
                'error_message'    => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            Log::error('[WA-Notify] DB log failed: ' . $e->getMessage());
        }
    }
}