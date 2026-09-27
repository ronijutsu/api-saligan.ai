<?php

use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureAiInternalSecret;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleCrmIdempotency;
use App\Http\Middleware\TrackLastUsed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('internal')
                ->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No statefulApi()/session middleware: the API authenticates a
        // Supabase bearer token on every request and keeps no session of its
        // own, so cookies, CSRF tokens, and the /sanctum/csrf-cookie priming
        // round-trip are all unnecessary.

        // The app is served behind nginx on the same host, so requests carry
        // X-Forwarded-* headers. Trusting the local proxy gives accurate
        // client IPs (for rate limiting) and lets HTTPS be detected. If the
        // box sits behind a remote load balancer or CDN, add that proxy's
        // address here instead.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // This is an API with no login page. Laravel's default guest redirect
        // targets a `login` route, which does not exist here, so an
        // unauthenticated request that does not ask for JSON (a browser
        // opening an endpoint directly, a health probe) would fail with a
        // RouteNotFoundException instead of the 401 it should be.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'is_admin' => EnsureUserIsAdmin::class,
            'ai.internal' => EnsureAiInternalSecret::class,
            'active_subscription' => EnsureActiveSubscription::class,
            'not_suspended' => EnsureNotSuspended::class,
            'terms.accepted' => EnsureTermsAccepted::class,
            'track_last_used' => TrackLastUsed::class,
            'crm.idempotency' => HandleCrmIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('internal/*'),
        );
        $exceptions->render(function (ValidationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->is('internal/*')) {
                return null;
            }

            return response()->json([
                'message' => 'The request could not be validated.',
                'code' => 'validation_failed',
                'errors' => $exception->errors(),
                'request_id' => $request->header('X-Request-Id') ?: (string) Str::uuid(),
            ], $exception->status);
        });
    })->create();
