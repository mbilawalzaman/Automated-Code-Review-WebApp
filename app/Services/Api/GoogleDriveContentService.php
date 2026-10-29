<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Services\Integrations\IntegrationCredentialService;
use RuntimeException;

class GoogleDriveContentService
{
    /**
     * Google Workspace formats have no byte stream and must be exported instead.
     */
    protected const EXPORTABLE = [
        'application/vnd.google-apps.document' => 'text/plain',
    ];

    protected function apiBase(): string
    {
        return (string) config('services.google_drive.api_base');
    }

    protected function tokenEndpoint(): string
    {
        return (string) config('services.google_drive.token_endpoint');
    }

    protected function webBase(): string
    {
        return (string) config('services.google_drive.web_base');
    }

    protected function scope(): string
    {
        return (string) config('services.google_drive.scope');
    }

    public function isConfigured(): bool
    {
        $creds = app(IntegrationCredentialService::class)->googleDrive();

        return $creds !== null;
    }

    /**
     * Live connectivity probe against the configured project-documents folder (UI-006).
     */
    public function probeFolder(): bool
    {
        $creds = app(IntegrationCredentialService::class)->googleDrive();
        if (! $creds) {
            return false;
        }

        $token = $this->accessToken();
        $response = Http::withToken($token)
            ->timeout(10)
            ->get($this->apiBase().'/files/'.$creds['folder_id'], [
                'fields' => 'id,name',
                'supportsAllDrives' => 'true',
            ]);

        return $response->successful();
    }

    /**
     * Fetch a document from the dedicated project-documents folder (SRS-001, SEC-005).
     *
     * @return array{content: string, filename: string, source_url: string, mime_type: string}
     */
    public function fetchFile(string $fileIdOrUrl): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Drive is not configured (GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON and GOOGLE_DRIVE_FOLDER_ID).');
        }

        $fileId = $this->extractFileId($fileIdOrUrl);
        $token = $this->accessToken();

        $metadata = Http::withToken($token)->get($this->apiBase()."/files/{$fileId}", [
            'fields' => 'id,name,mimeType,parents',
            'supportsAllDrives' => 'true',
        ]);

        if ($metadata->status() === 404) {
            throw new RuntimeException('Drive file not found, or not shared with the service account.');
        }

        if (! $metadata->successful()) {
            throw new RuntimeException("Google Drive API returned HTTP {$metadata->status()} while reading file metadata.");
        }

        $folderId = $this->configuredFolderId();
        $parents = (array) $metadata->json('parents', []);

        if ($parents === []) {
            throw new RuntimeException(
                'Could not verify the file\'s folder. Share the dedicated Drive folder (not only the file) with the service account as Viewer, then try again.'
            );
        }

        if (! in_array($folderId, $parents, true)) {
            throw new RuntimeException(
                'Refused: the file is outside the dedicated project-documents folder. Move it into that folder, or update GOOGLE_DRIVE_FOLDER_ID to match the folder shown in Drive.'
            );
        }

        $mimeType = (string) $metadata->json('mimeType', 'application/octet-stream');

        if (isset(self::EXPORTABLE[$mimeType])) {
            $download = Http::withToken($token)->get($this->apiBase()."/files/{$fileId}/export", [
                'mimeType' => self::EXPORTABLE[$mimeType],
            ]);
            $mimeType = self::EXPORTABLE[$mimeType];
        } else {
            $download = Http::withToken($token)->get($this->apiBase()."/files/{$fileId}", [
                'alt' => 'media',
                'supportsAllDrives' => 'true',
            ]);
        }

        if (! $download->successful()) {
            throw new RuntimeException("Google Drive API returned HTTP {$download->status()} while downloading the file.");
        }

        return [
            'content' => $download->body(),
            'filename' => (string) $metadata->json('name', $fileId),
            'source_url' => $this->webBase()."/file/d/{$fileId}/view",
            'mime_type' => $mimeType,
        ];
    }

    /**
     * Exchange the service-account JWT for a short-lived read-only access token.
     */
    protected function accessToken(): string
    {
        $credentials = $this->credentials();
        $cacheKey = 'google_drive_access_token_' . md5($credentials['client_email']);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $now = time();
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => $this->scope(),
            'aud' => $this->tokenEndpoint(),
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        if ($subject = app(IntegrationCredentialService::class)->googleDrive()['subject'] ?? null) {
            $claims['sub'] = $subject;
        }

        $response = Http::asForm()->post($this->tokenEndpoint(), [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->signJwt($claims, $credentials['private_key']),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Google token endpoint returned HTTP {$response->status()}.");
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if ($token === '') {
            throw new RuntimeException('Google token endpoint did not return an access token.');
        }

        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    protected function credentials(): array
    {
        $resolved = app(IntegrationCredentialService::class)->googleDrive();
        $raw = (string) ($resolved['service_account'] ?? '');

        // Support either inline JSON or a path to the key file on disk.
        if (! str_starts_with(trim($raw), '{')) {
            if (! is_readable($raw)) {
                throw new RuntimeException('Google Drive service account file is missing or unreadable.');
            }
            $raw = (string) file_get_contents($raw);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new RuntimeException('Google Drive service account JSON is invalid (client_email/private_key missing).');
        }

        return [
            'client_email' => (string) $decoded['client_email'],
            'private_key' => (string) $decoded['private_key'],
        ];
    }

    protected function configuredFolderId(): string
    {
        $resolved = app(IntegrationCredentialService::class)->googleDrive();

        return (string) ($resolved['folder_id'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function signJwt(array $claims, string $privateKey): string
    {
        $segments = [
            $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode((string) json_encode($claims)),
        ];

        $key = openssl_pkey_get_private($privateKey);

        if (! $key) {
            throw new RuntimeException('Google Drive service account private key could not be parsed.');
        }

        $input = implode('.', $segments);
        openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

        return $input . '.' . $this->base64UrlEncode($signature);
    }

    protected function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    protected function extractFileId(string $fileIdOrUrl): string
    {
        if (! str_starts_with($fileIdOrUrl, 'http')) {
            return trim($fileIdOrUrl);
        }

        if (preg_match('#/(?:file/)?d/([a-zA-Z0-9_-]{10,})#', $fileIdOrUrl, $matches)) {
            return $matches[1];
        }

        if (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,})#', $fileIdOrUrl, $matches)) {
            return $matches[1];
        }

        if (preg_match('#/folders/[a-zA-Z0-9_-]{10,}#', $fileIdOrUrl)) {
            throw new RuntimeException('That is a Drive folder link. Open the document inside the folder and paste its own sharing link.');
        }

        throw new RuntimeException('Unrecognised Google Drive link. Paste the sharing URL or the file ID.');
    }
}
