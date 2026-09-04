<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return to_route('dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('success', 'A new verification link has been sent.');
    }
}
