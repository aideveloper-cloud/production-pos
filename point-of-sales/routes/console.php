<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('crm:sync-segments')->dailyAt('01:00');
// K Garden central access: apply WMS access changes made in ERPNext (no-op while disabled).
Schedule::command('kgarden:sync-access')->everyMinute()->withoutOverlapping();
Schedule::command('crm:generate-reminders')->dailyAt('01:15');
Schedule::command('reorder:generate')->dailyAt('02:00');
