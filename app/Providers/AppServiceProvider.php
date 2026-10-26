<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Auth limits are keyed by email + IP, not IP alone: every request
        // arrives through the Next.js proxy (Vercel), so the IP is shared by
        // all users and an IP-only limit would lock everyone out together.
        $byEmail = fn (Request $request): string => mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip();
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($byEmail($request)));
        RateLimiter::for('password-email', fn (Request $request) => Limit::perMinute(6)->by($byEmail($request)));

        // R11: the reset email links to the Next.js page, not a Laravel view.
        ResetPassword::createUrlUsing(fn (object $user, string $token): string => rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
    }
}
