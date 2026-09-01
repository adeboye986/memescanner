<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Jobs\RunDashboardCommand;
use App\Services\DashboardCommandRegistry;
use App\Services\SystemActivityService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DashboardActionController extends Controller
{
    public function __invoke(
        Request $request,
        string $action,
        DashboardCommandRegistry $commands,
        SystemActivityService $activities,
    ): RedirectResponse {
        abort_unless($commands->supports($action), 404);

        try {
            $chain = in_array($action, ['token-scan', 'momentum-scan'], true)
                ? Chain::fromInput($request->input('chain', Chain::Solana->value))
                : null;
            $activity = $activities->createManual($action, $chain);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['chain' => $exception->getMessage()]);
        } catch (DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        RunDashboardCommand::dispatch($activity->id);

        return back()->with('success', "{$activity->label} was queued.");
    }
}
