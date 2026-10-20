<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs a host cron entry: * * * * * cd /path/to/admin && php artisan schedule:run
Schedule::command('webmail:send-scheduled')->everyMinute()->withoutOverlapping();
