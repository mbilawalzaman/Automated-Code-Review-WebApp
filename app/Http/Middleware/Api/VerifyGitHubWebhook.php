<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGitHubWebhook
{
    /**
     * Handle an incoming request and verify GitHub webhook signature.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (!$signature) {
            return response()->json([
                'error' => 'Missing X-Hub-Signature-256 header',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $secret = config('github.webhook_secret');

        if (empty($secret)) {
            return response()->json([
                'error' => 'GitHub webhook secret not configured',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $payload = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'error' => 'Invalid webhook signature',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
