<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountPasswordRequest;
use Illuminate\Http\RedirectResponse;

class AccountPasswordController extends Controller
{
    public function __invoke(UpdateAccountPasswordRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);
        $request->session()->regenerate();

        return back()->with('success', 'Password updated securely.');
    }
}
