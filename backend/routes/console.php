<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$handleFailure = static fn (string $command) => static fn (Stringable $output) => Log::error('Scheduled command failed', [
    'command' => $command,
    'output' => trim((string) $output),
]);

Schedule::command('tickets:expire-holds')
    ->everyMinute()
    ->onFailure($handleFailure('tickets:expire-holds'));

Schedule::command('resale:expire-listings')
    ->everyMinute()
    ->onFailure($handleFailure('resale:expire-listings'));

Schedule::command('events:export')
    ->dailyAt('00:30')
    ->withoutOverlapping(60)
    ->onFailure($handleFailure('events:export'));
