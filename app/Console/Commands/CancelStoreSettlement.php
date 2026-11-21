<?php

namespace App\Console\Commands;

use App\Exceptions\SettlementException;
use App\Services\StoreSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Place in app/Console/Commands/CancelStoreSettlement.php
 *
 * Undo an UNPAID settlement created by the new method (typically a test run):
 *   php artisan settlement:cancel 57
 *
 * Its redemptions become unsettled again and can be picked up by the next run.
 * Refused when any payment exists or the settlement is already paid.
 */
class CancelStoreSettlement extends Command
{
    protected $signature = 'settlement:cancel {id : Settlement id} {--force : Allow on production}';

    protected $description = 'Cancel an unpaid settlement and release its redemptions';

    public function handle(StoreSettlementService $service): int
    {
        if (app()->environment('production') && !$this->option('force') && !config('settlement.allow_test_override')) {
            $this->error('Cancelling settlements on production needs --force.');
            return self::FAILURE;
        }

        try {
            $res = $service->cancelUnpaid((int) $this->argument('id'));
        } catch (SettlementException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Cancelled {$res['settlement_no']}; {$res['unlinked']} redemption(s) released.");
        Log::warning('settlement:cancel', ['id' => (int) $this->argument('id')] + $res);

        return self::SUCCESS;
    }
}