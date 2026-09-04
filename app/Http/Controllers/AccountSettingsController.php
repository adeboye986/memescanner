<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.edit', ['user' => $request->user()]);
    }

    public function update(UpdateAccountRequest $request): RedirectResponse
    {
        $user = $request->user();
        $emailChanged = $user->email !== $request->validated('email');
        $user->fill($request->validated());

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('success', 'Account details updated.');
    }
}
