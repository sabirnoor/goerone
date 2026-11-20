<?php

namespace App\Console\Commands;

use App\Services\StoreSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Place in app/Console/Commands/GenerateStoreSettlements.php
 *
 * Normal (this is what the scheduler runs):
 *   php artisan settlement:generate                 # all stores, due-days rule applies
 *   php artisan settlement:generate --preview       # calculate only, writes nothing
 *
 * Testing one store:
 *   php artisan settlement:generate --store=12 --from=2026-11-01 --to=2026-11-15 --preview
 *   php artisan settlement:generate --store=12 --from=2026-11-01 --to=2026-11-15
 *   php artisan settlement:generate --store=12 --ignore-due     # TESTING: ignore settlement days
 *
 * Safe to run repeatedly: already-settled redemptions are never picked up again.
 */
class GenerateStoreSettlements extends Command
{
    protected $signature = 'settlement:generate
        {--store= : Only this store id}
        {--from= : Only redemptions on/after this date (Y-m-d), needs --store}
        {--to= : Only redemptions on/before this date (Y-m-d), needs --store}
        {--ignore-due : TESTING ONLY, ignore the settlement due days, needs --store}
        {--preview : Calculate only, do not write}';

    protected $description = 'Generate store settlements for redemptions whose due days have passed';

    public function handle(StoreSettlementService $service): int
    {
        $storeId   = $this->option('store') ? (int) $this->option('store') : null;
        $from      = $this->option('from') ?: null;
        $to        = $this->option('to') ?: null;
        $ignoreDue = (bool) $this->option('ignore-due');
        $preview   = (bool) $this->option('preview');

        if (($from || $to || $ignoreDue) && !$storeId) {
            $this->error('--from, --to and --ignore-due can only be used together with --store.');
            return self::FAILURE;
        }
        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if ($value && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $this->error("--{$name} must be in Y-m-d format.");
                return self::FAILURE;
            }
        }
        if ($from && $to && $from > $to) {
            $this->error('--from must not be after --to.');
            return self::FAILURE;
        }
        if ($ignoreDue && app()->environment('production') && !config('settlement.allow_test_override')) {
            $this->error('--ignore-due is disabled on production.');
            return self::FAILURE;
        }

        $summary = $service->generateAll($storeId, $preview, null, [
            'from' => $from,
            'to' => $to,
            'ignore_due' => $ignoreDue,
        ]);

        $this->info(($preview ? '[preview] ' : '') . "Settlements: {$summary['generated']}, skipped: "
            . count($summary['skipped']) . ', failed: ' . count($summary['failed']));

        foreach ($summary['settlements'] as $s) {
            $this->line(sprintf(
                '  store %s: %d redemption(s), payable %s, receivable %s, net %s',
                $s['store_id'], $s['count'], $s['payable_to_store'], $s['receivable_from_store'], $s['net_amount']
            ));
        }
        foreach ($summary['skipped'] as $s) {
            $this->warn("Store {$s['store_id']} skipped: {$s['reason']}");
        }
        foreach ($summary['failed'] as $f) {
            $this->error("Store {$f['store_id']} failed: {$f['message']}");
        }

        Log::info('settlement:generate finished', [
            'preview' => $preview,
            'store' => $storeId,
            'from' => $from,
            'to' => $to,
            'ignore_due' => $ignoreDue,
            'generated' => $summary['generated'],
            'skipped' => count($summary['skipped']),
            'failed' => count($summary['failed']),
        ]);

        return empty($summary['failed']) ? self::SUCCESS : self::FAILURE;
    }
}