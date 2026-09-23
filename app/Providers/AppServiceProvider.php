<?php

namespace App\Providers;

use App\Models\User;
use App\Services\EthereumAccountingReviewerAllowlist;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('review-ethereum-accounting', fn (User $user): bool => $user->is_admin
            && in_array((string) $user->id, EthereumAccountingReviewerAllowlist::normalize(config('services.ethereum.accounting.reviewer_ids')), true));
        Gate::define('manage-settings', fn (User $user): bool => (bool) $user->is_admin);
        RateLimiter::for('telegram-webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
