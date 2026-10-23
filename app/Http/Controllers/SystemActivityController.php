<?php

namespace App\Http\Controllers;

use App\Services\OperationalHealthService;
use App\Services\SystemActivityService;
use App\Services\TradingEngine\TradingEngineHealthStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemActivityController extends Controller
{
    public function __invoke(
        Request $request,
        SystemActivityService $activities,
        OperationalHealthService $operationalHealth,
        TradingEngineHealthStatusService $engineHealth,
    ): JsonResponse {
        $currentActivities = $activities->activeData($request->user());
        $activityCollection = collect($currentActivities);

        return response()->json([
            'current_activity' => $activityCollection->where('triggered_by', 'manual')->sortByDesc('id')->first(),
            'current_activities' => $currentActivities,
            'recent_activities' => $activities->recentData(user: $request->user()),
            'running_actions' => $activityCollection
                ->filter(fn (array $activity): bool => $activity['triggered_by'] === 'manual' || $activity['action'] === 'paper-track')
                ->pluck('action_key')
                ->all(),
            'system_status' => $activities->systemStatus(),
            'operational_status' => $operationalHealth->status(),
            'engine_status' => $engineHealth->status(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
