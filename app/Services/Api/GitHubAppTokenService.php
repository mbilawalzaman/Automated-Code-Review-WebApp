<?php

namespace App\Services\Api;

use App\Services\Integrations\IntegrationCredentialService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubAppTokenService
{
    public function __construct(
        protected IntegrationCredentialService $credentials,
    ) {}

    /**
     * Get a valid GitHub authentication token. GitHub App installation tokens are
     * preferred because APIs such as Check Runs reject PATs (SEC-004); a PAT is
     * only used as a fallback when the App is not configured or unavailable.
     */
    public function getAccessToken(?int $installationId = null): ?string
    {
        return $this->getInstallationToken($installationId)
            ?: ($this->credentials->github()['token'] ?: null);
    }

    /**
     * Resolve (and cache) a GitHub App installation token.
     */
    protected function getInstallationToken(?int $installationId = null): ?string
    {
        $creds = $this->credentials->github();
        $appId = $creds['app_id'];
        $privateKey = $creds['private_key'];
        $instId = $installationId ?? $creds['installation_id'];

        if (empty($appId) || empty($privateKey) || empty($instId)) {
            return null;
        }

        // Tokens expire after 60 minutes; cache slightly below that.
        $cacheKey = "github_app_installation_token_{$instId}";

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $token = $this->requestInstallationToken($appId, $privateKey, $instId);

        if ($token) {
            Cache::put($cacheKey, $token, now()->addMinutes(50));
        }

        return $token;
    }

    /**
     * Exchange an App JWT for an installation access token.
     */
    protected function requestInstallationToken(string $appId, string $privateKey, int|string $instId): ?string
    {
        $jwt = $this->generateAppJwt($appId, $privateKey);
        if (! $jwt) {
            return null;
        }

        try {
            $response = Http::withToken($jwt)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post(config('github.api_base')."/app/installations/{$instId}/access_tokens");

            if ($response->successful()) {
                return $response->json('token');
            }

            Log::warning("GitHub App installation token request failed [{$response->status()}]: " . $response->body());
        } catch (\Throwable $e) {
            Log::warning("GitHub App token exception: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Generate RS256 JWT signed with GitHub App Private Key.
     */
    protected function generateAppJwt(string $appId, string $privateKey): ?string
    {
        try {
            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $now = time();
            $payload = json_encode([
                'iat' => $now - 60,
                'exp' => $now + (10 * 60),
                'iss' => $appId,
            ]);

            $base64UrlHeader = $this->base64UrlEncode($header);
            $base64UrlPayload = $this->base64UrlEncode($payload);

            $signatureInput = $base64UrlHeader . "." . $base64UrlPayload;

            $keyResource = openssl_pkey_get_private($privateKey);
            if (! $keyResource) {
                Log::warning("Invalid GitHub App Private Key format.");

                return null;
            }

            openssl_sign($signatureInput, $signature, $keyResource, OPENSSL_ALGO_SHA256);

            $base64UrlSignature = $this->base64UrlEncode($signature);

            return $signatureInput . "." . $base64UrlSignature;
        } catch (\Throwable $e) {
            Log::warning("Failed to generate GitHub App JWT: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Base64Url encoding helper.
     */
    protected function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
