<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for webhooks that never arrive; see App\Console\Commands\SyncGenerations.
Schedule::command('generations:sync')->everyMinute()->withoutOverlapping();
