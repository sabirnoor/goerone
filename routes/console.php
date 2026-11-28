<?php

use App\Services\NotifyMasterBellIcon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('vouchers:expire-pending')->everyFiveMinutes();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => NotifyMasterBellIcon::prune())->daily();

Schedule::command('settlement:generate')
    ->dailyAt('02:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(60)      // skip if a previous run is still going
    ->onOneServer()               // needs a cache driver with locks (redis/database/memcached)
    ->appendOutputTo(storage_path('logs/settlement.log'));