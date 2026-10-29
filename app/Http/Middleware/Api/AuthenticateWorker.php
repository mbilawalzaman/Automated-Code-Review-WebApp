<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWorker
{
    /**
     * Authenticate review worker requests via Bearer token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.worker.token');

        if (empty($expectedToken)) {
            return response()->json([
                'error' => 'Worker authentication token is not configured',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $token = $request->bearerToken() ?? $request->header('X-Worker-Token');

        if (! $token || ! hash_equals($expectedToken, (string) $token)) {
            return response()->json([
                'error' => 'Unauthorized worker token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
