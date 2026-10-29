<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// AUD-004: Automated daily encrypted system backup at midnight
Schedule::command('backup:create')->dailyAt('00:00');

// OPS-001: Check review worker health & outage alerting
Schedule::command('health:check-workers')->everyFiveMinutes()->withoutOverlapping();

// OPS-001: Sweep stuck jobs >30m in processing/running state
Schedule::command('jobs:sweep-stuck')->everyTenMinutes()->withoutOverlapping();

// OPS-001: Scheduler freshness heartbeat signal.
// Store an ISO-8601 string — caching Carbon objects can deserialize as
// __PHP_Incomplete_Class on some cache drivers and crash /admin/health.
Schedule::call(function () {
    Cache::put('scheduler:last_seen_at', now()->toIso8601String(), now()->addMinutes(10));
})->everyMinute();
