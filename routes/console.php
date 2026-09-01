<?php

use App\Models\SystemActivity;
use App\Services\SystemActivityService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$tokenScanActivity = null;
$momentumScanActivity = null;
$paperTrackActivity = null;

Schedule::command('tokens:scan')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->before(function () use (&$tokenScanActivity): void {
        $activities = app(SystemActivityService::class);
        $tokenScanActivity = $activities->createScheduled('token-scan');
        $activities->start($tokenScanActivity);
    })
    ->onSuccess(function (Stringable $output) use (&$tokenScanActivity): void {
        if ($tokenScanActivity instanceof SystemActivity) {
            app(SystemActivityService::class)->finish($tokenScanActivity, 0, $output->toString());
        }
    })
    ->onFailure(function (Stringable $output) use (&$tokenScanActivity): void {
        if ($tokenScanActivity instanceof SystemActivity) {
            app(SystemActivityService::class)->finish($tokenScanActivity, 1, $output->toString());
        }
    });

Schedule::command('tokens:momentum')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->before(function () use (&$momentumScanActivity): void {
        $activities = app(SystemActivityService::class);
        $momentumScanActivity = $activities->createScheduled('momentum-scan');
        $activities->start($momentumScanActivity);
    })
    ->onSuccess(function (Stringable $output) use (&$momentumScanActivity): void {
        if ($momentumScanActivity instanceof SystemActivity) {
            app(SystemActivityService::class)->finish($momentumScanActivity, 0, $output->toString());
        }
    })
    ->onFailure(function (Stringable $output) use (&$momentumScanActivity): void {
        if ($momentumScanActivity instanceof SystemActivity) {
            app(SystemActivityService::class)->finish($momentumScanActivity, 1, $output->toString());
        }
    });

Schedule::command('tokens:paper-track')
    ->everyMinute()
    ->withoutOverlapping()
    ->before(function () use (&$paperTrackActivity): void {
        $activities = app(SystemActivityService::class);
        $paperTrackActivity = $activities->createScheduled('paper-track');
        $activities->start($paperTrackActivity);
    })
    ->onSuccess(function (Stringable $output) use (&$paperTrackActivity): void {
        if ($paperTrackActivity instanceof SystemActivity) {
            app(SystemActivityService::class)->finish($paperTrackActivity, 0, $output->toString());
        }
    })
    ->onFailure(function (Stringable $output) use (&$paperTrackActivity): void {
        if ($paperTrackActivity instanceof SystemActivity) {
            app(SystemActivityService::class)->finish($paperTrackActivity, 1, $output->toString());
        }
    });
