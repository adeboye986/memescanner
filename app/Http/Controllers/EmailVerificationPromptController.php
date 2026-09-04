<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationPromptController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail() ? to_route('dashboard') : view('auth.verify-email');
    }
}
