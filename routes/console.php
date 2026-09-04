<?php

use App\Models\SystemActivity;
use App\Services\OperationalHealthService;
use App\Services\SystemActivityService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::useCache((string) config('services.operations.cache_store', 'file'));

Schedule::call(fn () => app(OperationalHealthService::class)->recordSchedulerRun())
    ->name('operations.scheduler-heartbeat')
    ->everyMinute();

$paperTrackActivity = null;

Schedule::command('tokens:paper-track')
    ->everyTenSeconds()
    ->withoutOverlapping()
    ->skip(fn (): bool => Cache::store((string) config('services.trading.paper_tracker_cache_store', 'file'))
        ->lock('paper-tracker.fast.process')
        ->isLocked())
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
