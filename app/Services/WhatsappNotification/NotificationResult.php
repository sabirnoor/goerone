<?php

namespace App\Services\WhatsappNotification;

/**
 * Simple value object returned by GoeroneNotificationService::send().
 * Lets callers check outcomes without coupling to internals.
 */
class NotificationResult
{
    public function __construct(
        public readonly int   $sent,
        public readonly int   $failed,
        public readonly array $errors = [],   // ['phone' => 'error message', ...]
    ) {}

    /** True only when every recipient was sent successfully. */
    public function allSent(): bool
    {
        return $this->failed === 0 && $this->sent > 0;
    }

    /** True when at least one message was sent. */
    public function anySent(): bool
    {
        return $this->sent > 0;
    }

    /** True when every send failed (nothing went through). */
    public function totalFailure(): bool
    {
        return $this->sent === 0 && $this->failed > 0;
    }

    public function total(): int
    {
        return $this->sent + $this->failed;
    }
}
