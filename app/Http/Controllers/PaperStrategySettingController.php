<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePaperStrategyRequest;
use App\Services\PaperStrategyService;
use Illuminate\Http\RedirectResponse;

class PaperStrategySettingController extends Controller
{
    public function __invoke(
        UpdatePaperStrategyRequest $request,
        PaperStrategyService $strategies,
    ): RedirectResponse {
        $strategies->updateGlobal($request->validated());

        return redirect()
            ->route('dashboard')
            ->with('success', 'Default paper-trading strategy updated. New positions will use these settings.');
    }
}
