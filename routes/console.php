<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('followups:send')->everyMinute()->withoutOverlapping();
Schedule::command('quotations:expire')->everyFiveMinutes()->withoutOverlapping();
