<?php

namespace App\Providers;

use App\Models\User;
use App\Support\PublicLookupSecurity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        $this->configurePublicLookupRateLimiters();

        foreach (User::PERMISSIONS as $permission) {
            Gate::define(
                $permission,
                fn (User $user): bool =>
                    $user->hasPermission($permission)
            );
        }
    }

    private function configurePublicLookupRateLimiters(): void
    {
        $this->configurePublicLookupRateLimiter(
            PublicLookupSecurity::TRACKING_LIMITER,
            'tracking'
        );
        $this->configurePublicLookupRateLimiter(
            PublicLookupSecurity::QR_LIMITER,
            'qr'
        );
    }

    private function configurePublicLookupRateLimiter(
        string $name,
        string $category
    ): void {
        RateLimiter::for($name, function (Request $request) use ($category) {
            $policy = PublicLookupSecurity::limiterPolicy($category);

            if ($policy === null) {
                return response()->json([
                    'message' => 'Public lookup is temporarily unavailable.',
                ], 503);
            }

            return (new Limit(
                '',
                $policy['max_attempts'],
                $policy['decay_seconds']
            ))
                ->by(PublicLookupSecurity::limiterKey($request, $category))
                ->response(fn (Request $request, array $headers) =>
                    response()->json([
                        'message' => 'Too many requests. Please try again later.',
                    ], 429, $headers)
                );
        });
    }
}