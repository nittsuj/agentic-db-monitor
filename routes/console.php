<?php

use App\Jobs\CollectMetrics;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new CollectMetrics)->everyFiveMinutes()->withoutOverlapping();

Schedule::command('approvals:expire')->everyTenMinutes()->withoutOverlapping();

Schedule::command('model:prune')->hourly()->withoutOverlapping();
