<?php

namespace App\Http\Controllers;

use App\Services\SystemActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemActivityController extends Controller
{
    public function __invoke(Request $request, SystemActivityService $activities): JsonResponse
    {
        return response()->json([
            'current_activity' => $activities->currentManualData($request->user()),
            'recent_activities' => $activities->recentData(user: $request->user()),
            'running_actions' => $activities->runningActions($request->user()),
            'system_status' => $activities->systemStatus(),
        ]);
    }
}
