<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule; // <-- IMPORTANT (not the facade)

Artisan::command('meili:configure-screening-subjects', function () {
    $this->call(\App\Console\Commands\ConfigureMeiliScreeningSubjects::class);
})->purpose('Configure Meilisearch index settings for screening_subjects');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

app()->booted(function () {
    /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
    $schedule = app(Schedule::class);

    $schedule->command('sanctions:sync --limit=2000 --chunk=200')
        ->dailyAt('02:00')
        ->withoutOverlapping();

    $schedule->command('sanctions:test --limit=2000 --chunk=200')
        ->dailyAt('02:00')
        ->withoutOverlapping();
});