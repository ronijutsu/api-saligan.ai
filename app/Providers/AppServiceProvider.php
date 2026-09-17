<?php

namespace App\Providers;

use App\Auth\SupabaseGuard;
use App\Services\Auth\SupabaseJwtService;
use App\Services\Chat\ChatService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ChatService keeps per-request state (the user and assistant message
        // IDs it creates/persists so the controller can roll them back).
        // Scoped resolution guarantees a fresh instance per request and is
        // flushed between requests under Octane, so state can never leak
        // across users. Do not change this to a singleton.
        $this->app->scoped(ChatService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('supabase', function ($app, string $name, array $config) {
            $guard = new SupabaseGuard(
                $app->make(SupabaseJwtService::class),
                $app->make('auth')->createUserProvider($config['provider']),
                $app->make('request'),
            );

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
        RateLimiter::for('demo-request', function (Request $request) {
            return [
                // Per-IP guard so a single client cannot flood the form.
                Limit::perHour(5)->by('demo-request.ip.'.$request->ip()),
                // Per-email guard so one address cannot generate many leads.
                Limit::perDay(3)->by('demo-request.email.'.strtolower($request->input('email', ''))),
            ];
        });

        // The login screen's last-used lookup. Tight per-IP limit so the
        // endpoint cannot be walked as an account-enumeration oracle.
        RateLimiter::for('last-used-lookup', function (Request $request) {
            return Limit::perMinute(10)->by('last-used.ip.'.$request->ip());
        });

        // Registration. Per-IP guard so a single client cannot create many
        // accounts, and a per-email guard so one address cannot be hammered
        // with confirmation links. The response never reveals whether the
        // address is already registered, so the limiter is the backstop that
        // keeps the endpoint from being walked.
        RateLimiter::for('registration', function (Request $request) {
            return [
                Limit::perHour(20)->by('registration.ip.'.$request->ip()),
                Limit::perDay(10)->by('registration.email.'.strtolower((string) $request->input('email', ''))),
            ];
        });

        // CRM writes are authenticated but still bounded per actor and source
        // IP. The defaults remain deployment-configurable pending approval of
        // the final CRM mutation policy.
        RateLimiter::for('crm-mutation', function (Request $request) {
            $perMinute = max(1, (int) config('crm.mutations_per_minute', 60));

            return [
                Limit::perMinute($perMinute)->by('crm-mutation.user.'.($request->user('supabase')?->getAuthIdentifier() ?? 'guest')),
                Limit::perMinute($perMinute)->by('crm-mutation.ip.'.$request->ip()),
            ];
        });
    }
}
