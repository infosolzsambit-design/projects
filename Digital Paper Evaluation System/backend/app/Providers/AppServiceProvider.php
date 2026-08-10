<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Super Admin bypasses every permission check. Matched by role ID
        // (config('roles.super_admin_id')), never by role name.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole(config('roles.super_admin_id')) ? true : null;
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('login')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
