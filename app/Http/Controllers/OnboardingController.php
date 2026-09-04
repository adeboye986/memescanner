<?php

namespace App\Http\Controllers;

use App\Services\OnboardingStatusService;
use App\Services\PaperStrategyService;
use App\Services\UserTradingBootstrapService;
use App\Services\UserTradingPreferenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __invoke(
        Request $request,
        UserTradingBootstrapService $bootstrap,
        OnboardingStatusService $onboarding,
        UserTradingPreferenceService $preferences,
        PaperStrategyService $strategies,
    ): View {
        $user = $request->user();
        $bootstrap->bootstrap($user);

        return view('onboarding', [
            'status' => $onboarding->forUser($user->fresh()),
            'preference' => $preferences->forUser($user),
            'strategy' => $strategies->forUser($user),
            'bot' => $user->telegramBot()->with('identity')->first(),
        ]);
    }
}
