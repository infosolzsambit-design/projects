<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
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

        // Covers both /forgot-password and /reset-password — keyed by the
        // submitted email (not the authenticated user, there isn't one) plus
        // IP, same shape as 'login' above. Keeps someone from hammering
        // SendGrid with reset emails for an address they don't own, without
        // being so tight it blocks a real user re-requesting a link.
        RateLimiter::for('password-reset', function (Request $request) {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // This app is an API-only SPA — there's no "password.reset" web
        // route for the default ResetPassword notification to link to, so
        // it's pointed at the Vue app's own /reset-password page instead
        // (see ResetPasswordView.vue), and the mail content is branded
        // instead of Laravel's generic default.
        //
        // Note: once toMailUsing() is set, ResetPassword::toMail() calls it
        // directly instead of its own resetUrl() — so createUrlCallback is
        // never consulted for the *mail* content, only if something else
        // calls resetUrl() directly. Both callbacks get the raw token (not
        // a URL), so the URL is built once here and reused by both.
        $buildResetUrl = fn (User $notifiable, string $token): string => config('app.frontend_url')
            .'/reset-password?token='.$token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());

        ResetPassword::createUrlUsing($buildResetUrl);

        // Fully custom branded HTML (resources/views/emails/reset-password
        // .blade.php) instead of Laravel's generic component-based mail
        // theme — matches the app's own gradient/logo/typography instead of
        // looking like a stock framework notification.
        ResetPassword::toMailUsing(function (User $notifiable, string $token) use ($buildResetUrl) {
            return (new MailMessage)
                ->subject('Reset Your Password - CJ Paper Check')
                ->view('emails.reset-password', [
                    'name' => $notifiable->name,
                    'url' => $buildResetUrl($notifiable, $token),
                    'expireMinutes' => (int) config('auth.passwords.users.expire'),
                    'logoUrl' => config('app.frontend_url').'/images/logo-image.png',
                ]);
        });
    }
}
