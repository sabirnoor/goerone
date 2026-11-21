<?php

namespace App\Console\Commands;

use App\Services\VoucherSettlementLedger;
use Illuminate\Console\Command;

/**
 * Place in app/Console/Commands/SyncVoucherLedger.php
 *
 * Safety net for voucher settlement: posts PAID voucher orders that have no ledger row yet
 * (for example if posting failed once). Safe to run repeatedly.
 *
 *   php artisan settlement:vouchers-sync               # last 7 days
 *   php artisan settlement:vouchers-sync --since=2026-11-01
 *
 * Orders placed before the checkout change have no settlement snapshot and are skipped (see laravel.log).
 */
class SyncVoucherLedger extends Command
{
    protected $signature = 'settlement:vouchers-sync {--since= : Y-m-d, default 7 days ago}';

    protected $description = 'Post paid voucher orders that are missing from the settlement ledger';

    public function handle(VoucherSettlementLedger $ledger): int
    {
        $since = $this->option('since') ?: null;
        if ($since && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be in Y-m-d format.');
            return self::FAILURE;
        }

        $r = $ledger->postMissing($since);
        $this->info("Orders checked: {$r['orders']}, ledger rows posted: {$r['rows']}, failed: {$r['failed']}");

        return $r['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}