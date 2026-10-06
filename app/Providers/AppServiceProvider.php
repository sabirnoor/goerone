<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Voucher\Gateways\VoucherPaymentGateway;
use App\Services\Voucher\Gateways\AtomVoucherGateway;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VoucherPaymentGateway::class, function () {
            return match (config('voucher.gateway')) {
                'atom'  => new AtomVoucherGateway(),
                default => throw new \InvalidArgumentException('Unknown voucher payment gateway.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
