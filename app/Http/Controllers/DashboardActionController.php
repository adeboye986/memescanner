<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Jobs\RunDashboardCommand;
use App\Services\DashboardCommandRegistry;
use App\Services\SystemActivityService;
use App\Services\TradingEngine\TradingEngineScanCommandFactory;
use App\Services\TradingEngine\TradingEngineScanDeliveryTracker;
use App\Services\TradingEngine\TradingEngineScannerOwnership;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        abort_if($action === 'paper-track' && ! $request->user()->is_admin, 403);

        try {
            $chain = in_array($action, ['token-scan', 'momentum-scan'], true)
                ? Chain::fromInput($request->input('chain', Chain::Solana->value))
                : null;
            $engineOwned = config('services.trading_engine.scanner_integration_enabled', false) === true
                && app(TradingEngineScannerOwnership::class)->routes($request->user(), $action);

            if ($engineOwned) {
                $activity = DB::transaction(function () use (
                    $action,
                    $chain,
                    $request,
                    $activities,
                ) {
                    $activity = $activities->createManual($action, $chain, $request->user());
                    $commands = app(TradingEngineScanCommandFactory::class);
                    app(TradingEngineScanDeliveryTracker::class)->queue(
                        $activity,
                        $commands->make($activity, $request->user()),
                    );

                    return $activity;
                }, 3);
            } else {
                $activity = $activities->createManual($action, $chain, $request->user());
                RunDashboardCommand::dispatch($activity->id);
            }
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['chain' => $exception->getMessage()]);
        } catch (DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('success', "{$activity->label} was queued.");
    }
}
