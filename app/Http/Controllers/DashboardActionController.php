<?php

namespace App\Http\Controllers;

use App\Jobs\RunDashboardCommand;
use App\Services\DashboardCommandRegistry;
use App\Services\SystemActivityService;
use DomainException;
use Illuminate\Http\RedirectResponse;

class DashboardActionController extends Controller
{
    public function __invoke(
        string $action,
        DashboardCommandRegistry $commands,
        SystemActivityService $activities,
    ): RedirectResponse {
        abort_unless($commands->supports($action), 404);

        try {
            $activity = $activities->createManual($action);
        } catch (DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        RunDashboardCommand::dispatch($activity->id);

        return back()->with('success', "{$activity->label} was queued.");
    }
}
