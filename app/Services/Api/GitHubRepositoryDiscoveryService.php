<?php

namespace App\Services\Api;

use App\Models\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discover repositories available to the configured GitHub App installation (ONB-001).
 */
class GitHubRepositoryDiscoveryService
{
    public function __construct(
        protected GitHubAppTokenService $tokenService,
    ) {}

    /**
     * Whether App credentials are configured for discovery.
     */
    public function isAvailable(): bool
    {
        return ! empty(config('github.app_id'))
            && ! empty(config('github.private_key'))
            && ! empty(config('github.installation_id'));
    }

    /**
     * List installation repositories, marking ones already registered in the dashboard.
     *
     * @return array{
     *     available: bool,
     *     message: string|null,
     *     repositories: list<array{
     *         github_repo_id: int,
     *         name: string,
     *         full_name: string,
     *         default_branch: string,
     *         private: bool,
     *         html_url: string,
     *         already_registered: bool
     *     }>
     * }
     */
    public function listInstallationRepositories(int $perPage = 100, int $maxPages = 5): array
    {
        if (! $this->isAvailable()) {
            return [
                'available' => false,
                'message' => 'GitHub App is not configured. Enter the repository manually (owner/name).',
                'repositories' => [],
            ];
        }

        $token = $this->tokenService->getAccessToken();
        if (! $token) {
            return [
                'available' => false,
                'message' => 'Unable to obtain a GitHub App installation token. Enter the repository manually.',
                'repositories' => [],
            ];
        }

        $repositories = [];
        $page = 1;

        try {
            while ($page <= $maxPages) {
                $response = Http::withToken($token)
                    ->withHeaders([
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->get(config('github.api_base').'/installation/repositories', [
                        'per_page' => $perPage,
                        'page' => $page,
                    ]);

                if (! $response->successful()) {
                    Log::warning('GitHub installation repository discovery failed.', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return [
                        'available' => false,
                        'message' => 'GitHub discovery failed (HTTP '.$response->status().'). Enter the repository manually.',
                        'repositories' => [],
                    ];
                }

                $batch = $response->json('repositories') ?? [];
                if ($batch === []) {
                    break;
                }

                foreach ($batch as $repo) {
                    $repositories[] = [
                        'github_repo_id' => (int) ($repo['id'] ?? 0),
                        'name' => (string) ($repo['name'] ?? ''),
                        'full_name' => (string) ($repo['full_name'] ?? ''),
                        'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
                        'private' => (bool) ($repo['private'] ?? false),
                        'html_url' => (string) ($repo['html_url'] ?? ''),
                        'already_registered' => false,
                    ];
                }

                $totalCount = (int) ($response->json('total_count') ?? count($repositories));
                if (count($repositories) >= $totalCount || count($batch) < $perPage) {
                    break;
                }

                $page++;
            }
        } catch (\Throwable $e) {
            Log::warning('GitHub repository discovery exception: '.$e->getMessage());

            return [
                'available' => false,
                'message' => 'GitHub discovery error. Enter the repository manually.',
                'repositories' => [],
            ];
        }

        $registeredFullNames = Repository::query()
            ->pluck('full_name')
            ->map(fn (string $name): string => strtolower($name))
            ->all();

        $registeredIds = Repository::query()
            ->whereNotNull('github_repo_id')
            ->pluck('github_repo_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($repositories as &$repo) {
            $repo['already_registered'] = in_array(strtolower($repo['full_name']), $registeredFullNames, true)
                || ($repo['github_repo_id'] > 0 && in_array($repo['github_repo_id'], $registeredIds, true));
        }
        unset($repo);

        usort($repositories, fn (array $a, array $b): int => strcasecmp($a['full_name'], $b['full_name']));

        return [
            'available' => true,
            'message' => null,
            'repositories' => $repositories,
        ];
    }
}
