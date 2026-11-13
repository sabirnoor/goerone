<?php

namespace App\Jobs;

use App\Services\Notifications\GoerOneNotificationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Log;

class SendGoerOneNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // The runner never throws (each channel is wrapped), so a retry would only
    // re-send messages that already went out.
    public int $tries   = 1;
    public int $timeout = 120;

    public function __construct(
        public string $event,
        public array  $context,
        public ?array $only = null,
    ) {}

    public function handle(GoerOneNotificationRunner $runner): void
    {
        $runner->run($this->event, $this->context, $this->only);
    }
}