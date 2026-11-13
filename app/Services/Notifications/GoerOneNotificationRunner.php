<?php

namespace App\Services\Notifications;

use App\Services\EmailService;
use App\Services\WhatsappNotification\GoeroneNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Reads config/goerone_notifications.php and delivers mail + WhatsApp for one event.
 * Every channel of every recipient is isolated: one failure never blocks the rest.
 */
class GoerOneNotificationRunner
{
    public function __construct(private EmailService $emails) {}

    public function run(string $event, array $ctx, ?array $only = null): void
    {
        $def = config("goerone_notifications.events.{$event}");

        if (!is_array($def)) {
            Log::warning('[GoerOneNotify] unknown event', ['event' => $event]);
            return;
        }

        $built = [];
        try {
            $built = $def['builder']::build($ctx);

            foreach ($def['recipients'] as $type => $channels) {
                if ($only !== null && !in_array($type, $only, true)) {
                    continue;
                }
                if (empty($built[$type])) {
                    continue;
                }

                $r = $built[$type];

                if (!empty($channels['mail'])) {
                    $this->safely("{$event}:{$type}:mail", fn () => $this->mail($channels['mail'], $r, $ctx));
                }
                if (!empty($channels['whatsapp'])) {
                    $this->safely("{$event}:{$type}:whatsapp", fn () => $this->whatsapp($channels['whatsapp'], $type, $r, $ctx));
                }
            }
        } catch (\Throwable $e) {
            Log::error("[GoerOneNotify] {$event} failed", ['error' => $e->getMessage()]);
        } finally {
            $this->cleanup($built);
        }
    }

    private function mail(array $cfg, array $r, array $ctx): void
    {
        if (empty($r['email'])) {
            return;
        }

        $subject = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($r['data'][$m[1]] ?? ''), $cfg['subject']);
        $html    = view($cfg['view'], $r['data'])->render();
        $files   = $r['attachments'] ?? [];

        if ($files) {
            // ⚠ Adapt to EmailService: needs to accept attachments [['path'=>..., 'name'=>...]]
            $this->emails->sendEmail($r['user'], $r['email'], $subject, $html, $ctx['ip'] ?? '', $files);
        } else {
            $this->emails->sendEmail($r['user'], $r['email'], $subject, $html, $ctx['ip'] ?? '');
        }
    }

    private function whatsapp(string $waEvent, string $type, array $r, array $ctx): void
    {
        if (empty($r['phone'])) {
            return;
        }

        GoeroneNotificationService::notify(
            $waEvent,
            $r['user'],
            $r['data'] + ["{$type}_phone" => $r['phone']],
            reference: $r['data']['ref'] ?? ($ctx['ref'] ?? null),
            only: [$type],
        );
    }

    private function cleanup(array $built): void
    {
        foreach ($built as $r) {
            foreach (($r['attachments'] ?? []) as $f) {
                if (!empty($f['temp']) && is_file($f['path'])) {
                    @unlink($f['path']);
                }
            }
        }
    }

    private function safely(string $what, \Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error("[GoerOneNotify] {$what} failed", ['error' => $e->getMessage()]);
        }
    }
}