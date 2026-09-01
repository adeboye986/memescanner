<?php

namespace App\Http\Controllers;

use App\Services\SystemActivityService;
use Illuminate\Http\JsonResponse;

class SystemActivityController extends Controller
{
    public function __invoke(SystemActivityService $activities): JsonResponse
    {
        return response()->json([
            'activity' => $activities->latestManualData(),
            'running_actions' => $activities->runningActions(),
            'system_status' => $activities->systemStatus(),
        ]);
    }
}
