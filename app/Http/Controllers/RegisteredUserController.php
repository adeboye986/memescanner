<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterUserRequest;
use App\Models\User;
use App\Services\UserTradingBootstrapService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterUserRequest $request, UserTradingBootstrapService $bootstrap): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $bootstrap): User {
            $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));
            $bootstrap->bootstrap($user);

            return $user;
        });
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('onboarding');
    }
}
