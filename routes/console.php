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

$paperTrackActivity = null;

Schedule::command('tokens:paper-track')
    ->everyTenSeconds()
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
