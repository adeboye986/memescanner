<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// New launch scanner
Schedule::command('tokens:scan')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Momentum scanner
Schedule::command('tokens:momentum')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Paper position tracker
Schedule::command('tokens:paper-track')
    ->everyMinute()
    ->withoutOverlapping();