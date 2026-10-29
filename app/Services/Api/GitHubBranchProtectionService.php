<?php

namespace App\Services\Api;

use App\Models\Branch;
use App\Models\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Apply GitHub branch protection for main/staging (WF-004).
 * Merges with existing rules so other required checks / CI stay intact (AC-002 / ONB-004).
 */
class GitHubBranchProtectionService
{
    public function __construct(
        protected GitHubAppTokenService $tokenService
    ) {}

    /**
     * Human-readable preview of proposed protection (partial ONB-003).
     *
     * @return array{
     *     branches: list<string>,
     *     require_pull_request: bool,
     *     required_approving_review_count: int,
     *     required_status_checks: list<string>,
     *     block_direct_push: bool,
     *     allow_force_pushes: bool,
     *     allow_deletions: bool,
     *     notes: list<string>
     * }
     */
    public function preview(Repository $repository): array
    {
        return [
            'branches' => $this->targetBranchNames($repository),
            'require_pull_request' => true,
            'required_approving_review_count' => 1,
            'required_status_checks' => [$this->checkRunName()],
            'block_direct_push' => true,
            'allow_force_pushes' => false,
            'allow_deletions' => false,
            'notes' => [
                'Existing required status checks are preserved and merged.',
                'Existing dismissal restrictions, code-owner rules, bypass lists, and push restrictions are preserved when present.',
                'Branches that do not exist on GitHub are skipped.',
            ],
        ];
    }

    /**
     * Apply protection to configured branches. Never throws — activate must still succeed.
     *
     * @return array{
     *     applied: list<string>,
     *     skipped: list<array{branch: string, reason: string}>,
     *     preview: array<string, mixed>
     * }
     */
    public function applyForRepository(Repository $repository): array
    {
        $preview = $this->preview($repository);
        $applied = [];
        $skipped = [];

        $token = $this->tokenService->getAccessToken();
        if (! $token || ! $repository->full_name) {
            Log::info('Skipping branch protection: GitHub credentials not configured.', [
                'repository' => $repository->full_name,
            ]);

            foreach ($preview['branches'] as $branch) {
                $skipped[] = ['branch' => $branch, 'reason' => 'github_credentials_missing'];
            }

            return compact('applied', 'skipped', 'preview');
        }

        foreach ($preview['branches'] as $branchName) {
            $result = $this->protectBranch($repository, $branchName, $token);

            if ($result === true || $result === 'already_compliant') {
                $applied[] = $branchName;
            } else {
                $skipped[] = ['branch' => $branchName, 'reason' => $result ?: 'unknown'];
            }
        }

        if ($applied !== []) {
            $this->markLocalBranchesProtected($repository, $applied);
        }

        return compact('applied', 'skipped', 'preview');
    }

    /**
     * @return true|string True on success, or skip/error reason string.
     */
    protected function protectBranch(Repository $repository, string $branchName, string $token): true|string
    {
        $base = config('github.api_base')."/repos/{$repository->full_name}/branches/{$branchName}";

        try {
            $exists = Http::withToken($token)
                ->withHeaders($this->headers())
                ->timeout(15)
                ->get($base);

            if ($exists->status() === 404) {
                Log::info("Branch protection skipped: branch '{$branchName}' not found on {$repository->full_name}");

                return 'branch_not_found';
            }

            if (! $exists->successful()) {
                Log::warning("Branch lookup failed for {$branchName} on {$repository->full_name}: HTTP {$exists->status()}");

                return 'branch_lookup_failed';
            }

            $protectionUrl = "{$base}/protection";
            $existingResponse = Http::withToken($token)
                ->withHeaders($this->headers())
                ->timeout(15)
                ->get($protectionUrl);

            $existing = $existingResponse->successful() ? $existingResponse->json() : null;
            $payload = $this->buildProtectionPayload(is_array($existing) ? $existing : null);

            if (is_array($existing) && $this->isAlreadyCompliant($existing, $payload)) {
                Log::info("Branch protection already compliant on {$repository->full_name}@{$branchName}; skipping PUT.");

                return 'already_compliant';
            }

            $response = Http::withToken($token)
                ->withHeaders($this->headers())
                ->timeout(15)
                ->put($protectionUrl, $payload);

            if ($response->successful()) {
                Log::info("Applied branch protection on {$repository->full_name}@{$branchName}");

                return true;
            }

            if ($response->status() === 403) {
                Log::warning(
                    "Branch protection skipped: missing Administration permission for {$repository->full_name}@{$branchName}",
                    ['body' => $response->body()]
                );

                return 'missing_administration_permission';
            }

            Log::warning(
                "Branch protection API returned HTTP {$response->status()} for {$repository->full_name}@{$branchName}: "
                .$response->body()
            );

            return 'api_error_'.$response->status();
        } catch (\Throwable $e) {
            Log::warning("Branch protection failed for {$repository->full_name}@{$branchName}: ".$e->getMessage());

            return 'exception';
        }
    }

    /**
     * Merge our required check + minimum PR gate into existing protection (idempotent / coexistence).
     * Preserves dismissal restrictions, bypass lists, push restrictions, linear history, conversation
     * resolution, and related flags when GitHub already has them set.
     *
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    protected function buildProtectionPayload(?array $existing): array
    {
        $checkName = $this->checkRunName();
        $checks = [];
        if (is_array($existing['required_status_checks']['checks'] ?? null)) {
            foreach ($existing['required_status_checks']['checks'] as $check) {
                if (! is_array($check) || ! isset($check['context'])) {
                    continue;
                }

                $entry = ['context' => $check['context']];
                if (array_key_exists('app_id', $check) && $check['app_id'] !== null) {
                    $entry['app_id'] = $check['app_id'];
                }
                $checks[] = $entry;
            }
        }

        if (is_array($existing['required_status_checks']['contexts'] ?? null)) {
            foreach ($existing['required_status_checks']['contexts'] as $context) {
                if (! is_string($context) || $context === '') {
                    continue;
                }
                $already = collect($checks)->contains(fn ($c) => ($c['context'] ?? null) === $context);
                if (! $already) {
                    $checks[] = ['context' => $context];
                }
            }
        }

        $hasOurCheck = collect($checks)->contains(fn ($c) => ($c['context'] ?? null) === $checkName);
        if (! $hasOurCheck) {
            $checks[] = ['context' => $checkName];
        }

        $checks = collect($checks)
            ->unique(fn ($c) => ($c['context'] ?? '').'|'.($c['app_id'] ?? ''))
            ->values()
            ->all();

        $reviewCount = (int) (
            $existing['required_pull_request_reviews']['required_approving_review_count']
            ?? 1
        );
        if ($reviewCount < 1) {
            $reviewCount = 1;
        }

        $enforceAdmins = $existing['enforce_admins']['enabled']
            ?? $existing['enforce_admins']
            ?? true;

        $reviews = [
            'dismiss_stale_reviews' => (bool) (
                $existing['required_pull_request_reviews']['dismiss_stale_reviews'] ?? true
            ),
            'require_code_owner_reviews' => (bool) (
                $existing['required_pull_request_reviews']['require_code_owner_reviews'] ?? false
            ),
            'required_approving_review_count' => $reviewCount,
            'require_last_push_approval' => (bool) (
                $existing['required_pull_request_reviews']['require_last_push_approval'] ?? false
            ),
        ];

        $dismissal = $this->actorListsFromExisting(
            $existing['required_pull_request_reviews']['dismissal_restrictions'] ?? null
        );
        if ($dismissal !== null) {
            $reviews['dismissal_restrictions'] = $dismissal;
        }

        $bypass = $this->actorListsFromExisting(
            $existing['required_pull_request_reviews']['bypass_pull_request_allowances'] ?? null
        );
        if ($bypass !== null) {
            $reviews['bypass_pull_request_allowances'] = $bypass;
        }

        $payload = [
            'required_status_checks' => [
                'strict' => (bool) ($existing['required_status_checks']['strict'] ?? true),
                'checks' => array_values($checks),
            ],
            'enforce_admins' => (bool) $enforceAdmins,
            'required_pull_request_reviews' => $reviews,
            // WF-004: block force-push / delete on protected branches
            'allow_force_pushes' => false,
            'allow_deletions' => false,
            'required_linear_history' => (bool) (
                $existing['required_linear_history']['enabled']
                ?? $existing['required_linear_history']
                ?? false
            ),
            'required_conversation_resolution' => (bool) (
                $existing['required_conversation_resolution']['enabled']
                ?? $existing['required_conversation_resolution']
                ?? false
            ),
            'block_creations' => (bool) (
                $existing['block_creations']['enabled']
                ?? $existing['block_creations']
                ?? false
            ),
            'lock_branch' => (bool) (
                $existing['lock_branch']['enabled']
                ?? $existing['lock_branch']
                ?? false
            ),
            'allow_fork_syncing' => (bool) (
                $existing['allow_fork_syncing']['enabled']
                ?? $existing['allow_fork_syncing']
                ?? false
            ),
        ];

        // Preserve push restrictions when present; null = no restriction list (required by API)
        $restrictions = $this->actorListsFromExisting($existing['restrictions'] ?? null, includeApps: true);
        $payload['restrictions'] = $restrictions;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $_payload
     */
    protected function isAlreadyCompliant(array $existing, array $_payload): bool
    {
        $checkName = $this->checkRunName();

        $contexts = is_array($existing['required_status_checks']['contexts'] ?? null)
            ? $existing['required_status_checks']['contexts']
            : [];
        $checkContexts = collect($existing['required_status_checks']['checks'] ?? [])
            ->pluck('context')
            ->filter()
            ->all();

        $hasCheck = in_array($checkName, $contexts, true)
            || in_array($checkName, $checkContexts, true);

        $hasPrGate = isset($existing['required_pull_request_reviews'])
            && (int) ($existing['required_pull_request_reviews']['required_approving_review_count'] ?? 0) >= 1;

        $forceDisabled = ($existing['allow_force_pushes']['enabled'] ?? $existing['allow_force_pushes'] ?? true) === false;
        $deleteDisabled = ($existing['allow_deletions']['enabled'] ?? $existing['allow_deletions'] ?? true) === false;

        return $hasCheck && $hasPrGate && $forceDisabled && $deleteDisabled;
    }

    /**
     * Convert GitHub GET actor objects into PUT login/slug lists.
     *
     * @param  array<string, mixed>|null  $section
     * @return array{users: list<string>, teams: list<string>, apps?: list<string>}|null
     */
    protected function actorListsFromExisting(?array $section, bool $includeApps = false): ?array
    {
        if ($section === null) {
            return null;
        }

        $users = $this->extractActorNames($section['users'] ?? [], 'login');
        $teams = $this->extractActorNames($section['teams'] ?? [], 'slug');

        $result = [
            'users' => $users,
            'teams' => $teams,
        ];

        if ($includeApps) {
            $result['apps'] = $this->extractActorNames($section['apps'] ?? [], 'slug');
        }

        // Empty restriction object still means "restrictions enabled with empty lists"
        if ($users === [] && $teams === [] && (! $includeApps || ($result['apps'] ?? []) === [])) {
            // If the section existed but is empty arrays, still preserve as empty restrictions
            if (array_key_exists('users', $section) || array_key_exists('teams', $section) || array_key_exists('apps', $section)) {
                return $result;
            }

            return null;
        }

        return $result;
    }

    /**
     * @param  mixed  $actors
     * @return list<string>
     */
    protected function extractActorNames(mixed $actors, string $key): array
    {
        if (! is_array($actors)) {
            return [];
        }

        $names = [];
        foreach ($actors as $actor) {
            if (is_string($actor) && $actor !== '') {
                $names[] = $actor;
            } elseif (is_array($actor) && isset($actor[$key]) && is_string($actor[$key]) && $actor[$key] !== '') {
                $names[] = $actor[$key];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    protected function targetBranchNames(Repository $repository): array
    {
        /** @var list<string> $configured */
        $configured = config('github.protected_branches', ['main', 'master', 'staging']);

        $names = collect($configured)
            ->map(fn ($n) => strtolower(trim((string) $n)))
            ->filter()
            ->unique()
            ->values();

        $local = ($repository->relationLoaded('branches')
            ? $repository->branches->pluck('name')
            : $repository->branches()->pluck('name'))
            ->map(fn ($n) => strtolower((string) $n))
            ->all();

        $fromLocal = $names->filter(fn ($n) => in_array($n, $local, true));
        $targets = $fromLocal->isNotEmpty() ? $fromLocal : $names;

        $default = strtolower((string) ($repository->default_branch ?? ''));
        if ($default !== '' && $names->contains($default) && ! $targets->contains($default)) {
            $targets->push($default);
        }

        return $targets->unique()->values()->all();
    }

    /**
     * @param  list<string>  $branchNames
     */
    protected function markLocalBranchesProtected(Repository $repository, array $branchNames): void
    {
        if ($branchNames === []) {
            return;
        }

        Branch::query()
            ->where('repository_id', $repository->id)
            ->whereIn('name', $branchNames)
            ->update(['is_protected' => true]);
    }

    protected function markLocalBranchProtected(Repository $repository, string $branchName): void
    {
        $this->markLocalBranchesProtected($repository, [$branchName]);
    }

    protected function checkRunName(): string
    {
        return (string) config('github.check_run_name', 'Automated Code Review');
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }
}
