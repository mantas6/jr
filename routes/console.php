<?php

use Illuminate\Console\Scheduling\Schedule as Scheduling;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('jira:sync')
    ->everyFiveMinutes()
    ->days([
        Scheduling::MONDAY,
        Scheduling::TUESDAY,
        Scheduling::WEDNESDAY,
        Scheduling::THURSDAY,
    ])
    ->between('8:00', '16:00')
    ->withoutOverlapping();
