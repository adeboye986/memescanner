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
        $strategies->updateForUser($request->user(), $request->validated());

        return redirect()
            ->route('dashboard')
            ->with('success', 'Your paper-trading strategy was updated. New positions will use these settings.');
    }
}
