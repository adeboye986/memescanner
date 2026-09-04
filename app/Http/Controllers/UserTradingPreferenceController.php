<?php

namespace App\Http\Controllers;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Http\Requests\UpdateUserTradingPreferenceRequest;
use App\Services\UserTradingPreferenceService;
use Illuminate\Http\RedirectResponse;

class UserTradingPreferenceController extends Controller
{
    public function __invoke(UpdateUserTradingPreferenceRequest $request, UserTradingPreferenceService $preferences): RedirectResponse
    {
        $preferences->update(
            $request->user(),
            ExecutionMode::from($request->validated('execution_mode')),
            EntryMode::from($request->validated('entry_mode')),
        );

        return back()->with('success', 'Your paper trading modes were updated.');
    }
}
