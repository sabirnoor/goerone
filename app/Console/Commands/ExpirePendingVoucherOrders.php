<?php

namespace App\Console\Commands;

use App\Services\Voucher\VoucherPurchaseService;
use Illuminate\Console\Command;

class ExpirePendingVoucherOrders extends Command
{
    protected $signature   = 'vouchers:expire-pending';
    protected $description = 'Expire unpaid voucher orders and release their reserved stock';

    public function handle(VoucherPurchaseService $service): int
    {
        $count = $service->expirePending();
        $this->info("Expired {$count} pending voucher order(s).");

        return self::SUCCESS;
    }
}
