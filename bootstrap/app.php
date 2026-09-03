<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustConfiguredHosts;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([
            SecurityHeaders::class,
            TrustConfiguredHosts::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($response, \Throwable $_exception, Request $request) {
            if ($request->is('api', 'api/*') && $response->getStatusCode() >= 500) {
                $response = response()->json([
                    'message' => 'An unexpected error occurred.',
                ], 500);
            }

            return SecurityHeaders::applyTo($response, $request);
        });
    })->create();
