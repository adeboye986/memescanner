<?php

namespace App\Http\Controllers;

use App\Services\SystemActivityService;
use Illuminate\Http\JsonResponse;

class SystemActivityController extends Controller
{
    public function __invoke(SystemActivityService $activities): JsonResponse
    {
        return response()->json([
            'current_activity' => $activities->currentManualData(),
            'recent_activities' => $activities->recentData(),
            'running_actions' => $activities->runningActions(),
            'system_status' => $activities->systemStatus(),
        ]);
    }
}
