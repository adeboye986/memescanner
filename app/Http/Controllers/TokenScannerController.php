<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Http\Requests\TokenScanDetailRequest;
use App\Http\Requests\TokenScanIndexRequest;
use App\Services\ApplicationSettingsService;
use App\Services\IntegrationStatusService;
use App\Services\SystemActivityService;
use App\Services\TokenScanCandidateDiagnosticsService;
use App\Services\TokenScanHistoryService;
use Illuminate\Contracts\View\View;

class TokenScannerController extends Controller
{
    public function index(
        TokenScanIndexRequest $request,
        TokenScanHistoryService $history,
        SystemActivityService $activities,
        ApplicationSettingsService $settings,
        IntegrationStatusService $integrations,
    ): View {
        $user = $request->user();
        $filters = $request->filters();
        $latest = $history->latest($user);
        $integrationStatus = $integrations->all();

        return view('scanner.index', [
            'activeTab' => $request->tab(),
            'filters' => $filters,
            'history' => $history->paginate($filters, $request->integer('page', 1), $user),
            'latestScan' => $latest ? $history->present($latest) : null,
            'chains' => Chain::cases(),
            'runningActions' => $activities->runningActions($user),
            'scannerConfiguration' => [
                'max_chase_percent' => (float) $settings->get('scanner.max_chase_percent'),
                'momentum_birdeye_budget' => max(0, min(20, (int) config('services.birdeye.momentum_budget', 1))),
            ],
            'providerStatus' => [
                'market_data' => $integrationStatus['market_data'],
                'trading_engine' => $integrationStatus['trading_engine'],
                'solana' => $integrationStatus['solana'],
                'ethereum' => $integrationStatus['ethereum'],
            ],
        ]);
    }

    public function show(
        TokenScanDetailRequest $request,
        int $activity,
        TokenScanHistoryService $history,
        TokenScanCandidateDiagnosticsService $diagnostics,
    ): View {
        $model = $history->findForUser($activity, $request->user());

        return view('scanner.show', [
            'scan' => $history->present($model),
            'candidateDiagnostics' => $diagnostics->forActivity(
                $model,
                $request->user(),
                $request->candidatePage(),
            ),
        ]);
    }
}
