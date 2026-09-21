<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChangeIsComplete
{
    private const ALLOWED_PATHS = [
        'api/user',
        'api/logout',
        'api/me/password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user &&
            (bool) $user->must_change_password &&
            !in_array($request->path(), self::ALLOWED_PATHS, true)
        ) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
            ], 403);
        }

        return $next($request);
    }
}
