<?php

namespace App\Http\Middleware;

use App\Support\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustConfiguredHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!SecurityPolicy::hostIsTrusted($request->server('HTTP_HOST'))) {
            if (str_starts_with($request->path(), 'api/')) {
                return response()->json(['message' => 'Invalid request host.'], 400);
            }

            return response('Invalid request host.', 400);
        }

        return $next($request);
    }
}
