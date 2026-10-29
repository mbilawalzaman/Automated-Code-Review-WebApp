<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationType;
use App\Models\Integration;
use App\Services\Api\GitHubAppTokenService;
use App\Services\Api\GoogleDriveContentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LiveIntegrationHealthService
{
    public function __construct(
        protected IntegrationCredentialService $credentials,
        protected GitHubAppTokenService $gitHubAppTokenService,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function probeAll(): array
    {
        return [
            'github' => $this->probeGitHub(),
            'trello' => $this->probeTrello(),
            'google_drive' => $this->probeGoogleDrive(),
            'mail' => $this->probeMail(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function probeGitHub(): array
    {
        $creds = $this->credentials->github();
        $configured = filled($creds['token'])
            || (filled($creds['app_id']) && filled($creds['private_key']) && filled($creds['installation_id']));

        if (! $configured) {
            return $this->result('GitHub App Integration', false, 'Not Configured', 'bg-gray-100 text-gray-700', $creds['source']);
        }

        try {
            $token = $this->gitHubAppTokenService->getAccessToken(
                filled($creds['installation_id']) ? (int) $creds['installation_id'] : null
            );

            if (! $token) {
                return $this->result('GitHub App Integration', true, 'Auth Failed', 'bg-red-100 text-red-800', $creds['source'], 'Unable to obtain access token');
            }

            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(10)
                ->get(config('github.api_base').'/installation/repositories', ['per_page' => 1]);

            if ($response->status() === 403 || $response->status() === 404) {
                // PAT fallback path may not support installation endpoint.
                $response = Http::withToken($token)
                    ->withHeaders([
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->timeout(10)
                    ->get(config('github.api_base').'/user');
            }

            if ($response->successful()) {
                $this->touchHealth(IntegrationType::GitHub, 'healthy');

                return $this->result('GitHub App Integration', true, 'Connected', 'bg-green-100 text-green-800', $creds['source']);
            }

            $this->touchHealth(IntegrationType::GitHub, 'unhealthy');

            return $this->result('GitHub App Integration', true, 'Unreachable', 'bg-red-100 text-red-800', $creds['source'], 'HTTP '.$response->status());
        } catch (\Throwable $e) {
            Log::warning('GitHub live probe failed: '.$e->getMessage());
            $this->touchHealth(IntegrationType::GitHub, 'unhealthy');

            return $this->result('GitHub App Integration', true, 'Error', 'bg-red-100 text-red-800', $creds['source'], $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function probeTrello(): array
    {
        $creds = $this->credentials->trello();
        if (! $creds) {
            return $this->result('Trello Automation Account', false, 'Not Configured', 'bg-gray-100 text-gray-700', 'env');
        }

        try {
            $response = Http::timeout(10)->get(config('services.trello.api_base').'/members/me', [
                'key' => $creds['key'],
                'token' => $creds['token'],
                'fields' => 'id,username,fullName',
            ]);

            if ($response->successful()) {
                $this->touchHealth(IntegrationType::Trello, 'healthy');
                $username = $response->json('username');

                return $this->result(
                    'Trello Automation Account',
                    true,
                    'Connected',
                    'bg-green-100 text-green-800',
                    $creds['source'],
                    $username ? "Authenticated as @{$username}" : null
                );
            }

            $this->touchHealth(IntegrationType::Trello, 'unhealthy');

            return $this->result('Trello Automation Account', true, 'Unreachable', 'bg-red-100 text-red-800', $creds['source'], 'HTTP '.$response->status());
        } catch (\Throwable $e) {
            $this->touchHealth(IntegrationType::Trello, 'unhealthy');

            return $this->result('Trello Automation Account', true, 'Error', 'bg-red-100 text-red-800', $creds['source'], $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function probeGoogleDrive(): array
    {
        $creds = $this->credentials->googleDrive();
        if (! $creds) {
            return $this->result('Google Drive Documents', false, 'Not Configured', 'bg-gray-100 text-gray-700', 'env');
        }

        try {
            $drive = app(GoogleDriveContentService::class);
            if ($drive->probeFolder()) {
                $this->touchHealth(IntegrationType::GoogleDrive, 'healthy');

                return $this->result('Google Drive Documents', true, 'Connected', 'bg-green-100 text-green-800', $creds['source']);
            }

            $this->touchHealth(IntegrationType::GoogleDrive, 'unhealthy');

            return $this->result('Google Drive Documents', true, 'Unreachable', 'bg-red-100 text-red-800', $creds['source']);
        } catch (\Throwable $e) {
            $this->touchHealth(IntegrationType::GoogleDrive, 'unhealthy');

            return $this->result('Google Drive Documents', true, 'Error', 'bg-red-100 text-red-800', $creds['source'], $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function probeMail(): array
    {
        $configured = ! empty(config('mail.mailers.smtp.host')) || config('mail.default') === 'log';

        return $this->result(
            'Mail / SMTP Notifications',
            $configured,
            config('mail.default') === 'log' ? 'Configured (Log Mode)' : ($configured ? 'Configured' : 'Not Configured'),
            $configured ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700',
            'env'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function result(string $label, bool $configured, string $statusLabel, string $statusClass, string $source, ?string $detail = null): array
    {
        return [
            'label' => $label,
            'configured' => $configured,
            'status_label' => $statusLabel,
            'status_class' => $statusClass,
            'source' => $source,
            'detail' => $detail,
        ];
    }

    protected function touchHealth(IntegrationType $type, string $status): void
    {
        Integration::query()
            ->where('type', $type->value)
            ->whereNull('project_id')
            ->whereNull('repository_id')
            ->update([
                'health_status' => $status,
                'last_synced_at' => now(),
            ]);
    }
}
