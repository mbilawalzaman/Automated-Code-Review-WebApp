<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Repositories\Contracts\RepositoryRepositoryInterface;
use App\Repositories\Contracts\ReviewJobRepositoryInterface;
use App\Services\Api\GitHubBranchProtectionService;
use App\Services\Api\GitHubRepositoryDiscoveryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class RepositoryService
{
    public function __construct(
        private readonly RepositoryRepositoryInterface $repositoryRepository,
        private readonly AuditLogService $auditLogService,
        private readonly ReviewJobRepositoryInterface $reviewJobRepository,
        private readonly GitHubBranchProtectionService $branchProtectionService,
        private readonly GitHubRepositoryDiscoveryService $discoveryService,
        private readonly RepositoryActivationPreviewService $activationPreviewService,
    ) {
    }

    /**
     * @return Collection<int, Repository>
     */
    public function list(): Collection
    {
        return $this->repositoryRepository->all();
    }

    /**
     * @return LengthAwarePaginator<int, Repository>
     */
    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return $this->repositoryRepository->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Repository
    {
        $branchNames = $this->extractBranchNames($data);
        $repositoryData = $this->prepareRepositoryData($data);

        $repository = $this->repositoryRepository->create($repositoryData);
        $this->syncBranches($repository, $branchNames);

        $fresh = $repository->fresh(['project', 'branches']);

        $this->auditLogService->record(
            category: AuditCategory::Repository,
            eventType: AuditEventType::Created,
            action: "Repository connected: {$fresh->full_name}",
            severity: AuditSeverity::Info,
            target: $fresh,
            afterState: [
                'full_name' => $fresh->full_name,
                'default_branch' => $fresh->default_branch,
                'trello_board_id' => $fresh->trello_board_id,
                'trello_list_id' => $fresh->trello_list_id,
            ],
            reason: 'Repository onboarding'
        );

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): Repository
    {
        $repository = $this->findOrFail($id);
        $beforeState = [
            'name' => $repository->name,
            'full_name' => $repository->full_name,
            'default_branch' => $repository->default_branch,
            'trello_board_id' => $repository->trello_board_id,
            'trello_list_id' => $repository->trello_list_id,
            'status' => $repository->status->value ?? (string) $repository->status,
        ];

        $branchNames = $this->extractBranchNames($data);
        $repositoryData = $this->prepareRepositoryData($data);

        $updated = $this->repositoryRepository->update($repository, $repositoryData);
        $this->syncBranches($updated, $branchNames);

        $fresh = $updated->fresh(['project', 'branches']);
        $afterState = [
            'name' => $fresh->name,
            'full_name' => $fresh->full_name,
            'default_branch' => $fresh->default_branch,
            'trello_board_id' => $fresh->trello_board_id,
            'trello_list_id' => $fresh->trello_list_id,
            'status' => $fresh->status->value ?? (string) $fresh->status,
        ];

        $this->auditLogService->record(
            category: AuditCategory::Repository,
            eventType: AuditEventType::Updated,
            action: "Repository updated: {$fresh->full_name}",
            severity: AuditSeverity::Info,
            target: $fresh,
            beforeState: $beforeState,
            afterState: $afterState,
            reason: 'Repository settings update'
        );

        return $fresh;
    }

    public function delete(int $id): void
    {
        $repository = $this->findOrFail($id);
        $beforeState = [
            'full_name' => $repository->full_name,
        ];

        $this->repositoryRepository->delete($repository);

        $this->auditLogService->record(
            category: AuditCategory::Repository,
            eventType: AuditEventType::Deleted,
            action: "Repository deleted: {$repository->full_name}",
            severity: AuditSeverity::Warning,
            target: $repository,
            beforeState: $beforeState,
            reason: 'Repository removal'
        );
    }

    public function connect(int $id): Repository
    {
        return $this->setStatus($id, RepositoryStatus::Connected, [
            'connected_at' => now(),
        ]);
    }

    public function activate(int $id): Repository
    {
        $repository = $this->findOrFail($id);
        $wasActive = $repository->status === RepositoryStatus::Active;

        $updated = $this->setStatus($id, RepositoryStatus::Active, [
            'is_paused' => false,
            'connected_at' => now(),
        ]);

        // ONB-008: first transition to Active queues a baseline review
        if (! $wasActive) {
            $this->enqueueReviewJob(
                repository: $updated,
                triggerType: 'baseline_activation',
                branchName: $updated->default_branch ?? 'main',
                idempotencySeed: 'baseline_activation:'.$updated->id,
            );
        }

        // WF-004: apply / merge branch protection on activate
        $this->applyBranchProtection($updated, 'Repository activated');

        return $updated->fresh(['branches', 'project']) ?? $updated;
    }

    /**
     * WF-004: Explicitly (re)apply GitHub branch protection for main/staging.
     *
     * @return array{applied: list<string>, skipped: list<array{branch: string, reason: string}>, preview: array<string, mixed>}
     */
    public function protectBranches(int $id): array
    {
        $repository = $this->findOrFail($id);

        if ($repository->status === RepositoryStatus::Disconnected) {
            throw ValidationException::withMessages([
                'repository' => 'Connect the repository before applying branch protection.',
            ]);
        }

        return $this->applyBranchProtection($repository, 'Manual branch protection request');
    }

    /**
     * Partial ONB-003: proposed branch-protection changes.
     *
     * @return array<string, mixed>
     */
    public function branchProtectionPreview(int $id): array
    {
        return $this->branchProtectionService->preview($this->findOrFail($id));
    }

    /**
     * Full ONB-003 activation preview (webhooks, checks, permissions, protection).
     *
     * @return array<string, mixed>
     */
    public function activationPreview(int $id): array
    {
        return $this->activationPreviewService->preview($this->findOrFail($id));
    }

    /**
     * Discover GitHub App installation repositories (ONB-001).
     *
     * @return array{available: bool, message: string|null, repositories: list<array<string, mixed>>}
     */
    public function discoverRepositories(): array
    {
        return $this->discoveryService->listInstallationRepositories();
    }

    public function discoveryAvailable(): bool
    {
        return $this->discoveryService->isAvailable();
    }

    /**
     * @return array{applied: list<string>, skipped: list<array{branch: string, reason: string}>, preview: array<string, mixed>}
     */
    private function applyBranchProtection(Repository $repository, string $reason): array
    {
        $result = $this->branchProtectionService->applyForRepository($repository);

        $this->auditLogService->record(
            category: AuditCategory::Repository,
            eventType: AuditEventType::Updated,
            action: "Branch protection applied for {$repository->full_name}",
            severity: AuditSeverity::Warning,
            target: $repository,
            afterState: [
                'applied' => $result['applied'],
                'skipped' => $result['skipped'],
                'preview' => $result['preview'],
            ],
            reason: $reason
        );

        return $result;
    }

    public function pause(int $id): Repository
    {
        return $this->setStatus($id, RepositoryStatus::Paused, [
            'is_paused' => true,
        ]);
    }

    public function resume(int $id): Repository
    {
        return $this->setStatus($id, RepositoryStatus::Active, [
            'is_paused' => false,
        ]);
    }

    /**
     * ONB-007: Manually enqueue a review for a connected/active repository.
     */
    public function reviewNow(int $id, ?string $branch = null): ReviewJob
    {
        $repository = $this->findOrFail($id);

        if (in_array($repository->status, [RepositoryStatus::Disconnected, RepositoryStatus::Paused], true)) {
            throw ValidationException::withMessages([
                'repository' => 'Repository must be connected and not paused to run Review Now.',
            ]);
        }

        $branchName = $branch ?: ($repository->default_branch ?? 'main');

        // Coalesce onto an already-open job for this repo/branch instead of stacking duplicates.
        $openJob = $this->findOpenReviewJob($repository, commitSha: null, branchName: $branchName);
        if ($openJob) {
            $this->auditLogService->record(
                category: AuditCategory::Repository,
                eventType: AuditEventType::Updated,
                action: "Review Now coalesced onto open job #{$openJob->id} for {$repository->full_name} ({$branchName})",
                severity: AuditSeverity::Info,
                target: $repository,
                afterState: [
                    'review_job_id' => $openJob->id,
                    'branch' => $branchName,
                    'trigger_type' => 'manual_review_now',
                    'coalesced' => true,
                ],
                reason: 'Manual Review Now coalesced onto open job'
            );

            return $openJob;
        }

        $this->assertSameShaRequeueAllowed($repository, commitSha: 'HEAD', branchName: $branchName);

        $job = $this->enqueueReviewJob(
            repository: $repository,
            triggerType: 'manual_review_now',
            branchName: $branchName,
            idempotencySeed: 'manual_review_now:'.$repository->id.':'.$branchName.':'.now()->timestamp,
            forceNew: true,
        );

        $this->auditLogService->record(
            category: AuditCategory::Repository,
            eventType: AuditEventType::Updated,
            action: "Review Now queued for {$repository->full_name} ({$branchName})",
            severity: AuditSeverity::Info,
            target: $repository,
            afterState: [
                'review_job_id' => $job->id,
                'branch' => $branchName,
                'trigger_type' => 'manual_review_now',
            ],
            reason: 'Manual Review Now request'
        );

        return $job;
    }

    public function findOrFail(int $id): Repository
    {
        $repository = $this->repositoryRepository->findById($id);

        if ($repository === null) {
            abort(404);
        }

        return $repository;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function setStatus(int $id, RepositoryStatus $status, array $extra = []): Repository
    {
        $repository = $this->findOrFail($id);
        $beforeState = ['status' => $repository->status->value ?? (string) $repository->status];

        $updated = $this->repositoryRepository->update($repository, [
            ...$extra,
            'status' => $status,
        ]);

        $afterState = ['status' => $status->value];

        $this->auditLogService->record(
            category: AuditCategory::Repository,
            eventType: AuditEventType::Updated,
            action: "Repository status changed to {$status->value}: {$updated->full_name}",
            severity: AuditSeverity::Info,
            target: $updated,
            beforeState: $beforeState,
            afterState: $afterState
        );

        return $updated;
    }

    /**
     * Create a pending review job for the repository (idempotent unless forceNew).
     */
    private function enqueueReviewJob(
        Repository $repository,
        string $triggerType,
        string $branchName,
        string $idempotencySeed,
        bool $forceNew = false,
        ?string $commitSha = null,
    ): ReviewJob {
        $idempotencyKey = sha1($idempotencySeed);
        $resolvedSha = $commitSha ?? 'HEAD';

        if (! $forceNew) {
            $existing = ReviewJob::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        } else {
            $this->cancelPendingDuplicates($repository, $resolvedSha, $branchName);
        }

        return $this->reviewJobRepository->create([
            'repository_id' => $repository->id,
            'trigger_type' => $triggerType,
            'commit_sha' => $resolvedSha,
            'idempotency_key' => $forceNew ? sha1($idempotencySeed.':'.uniqid('', true)) : $idempotencyKey,
            'status' => ReviewJobStatus::Pending->value,
            'payload' => json_encode([
                'event' => $triggerType,
                'branch_name' => $branchName,
                'author' => auth()->user()?->name ?? 'Project Manager',
                'title' => $triggerType === 'baseline_activation'
                    ? "Baseline review for {$repository->full_name}"
                    : "Manual review for {$repository->full_name} ({$branchName})",
            ]),
        ]);
    }

    /**
     * Block re-queue loops after recent submission timeouts for the same SHA/branch.
     */
    private function assertSameShaRequeueAllowed(
        Repository $repository,
        string $commitSha,
        string $branchName,
    ): void {
        $cooldown = (int) config('worker.same_sha_requeue_cooldown_minutes', 60);
        if ($cooldown <= 0) {
            return;
        }

        $query = ReviewJob::query()
            ->where('repository_id', $repository->id)
            ->whereIn('status', [
                ReviewJobStatus::Failed->value,
                ReviewJobStatus::Completed->value,
            ])
            ->where('completed_at', '>=', now()->subMinutes($cooldown))
            ->where(function ($q) {
                $q->where('error_message', 'like', '%result_submission_timeout%')
                    ->orWhere('error_message', 'like', '%timed out after%');
            })
            ->orderByDesc('completed_at');

        if ($commitSha !== 'HEAD' && $commitSha !== '') {
            $query->where('commit_sha', $commitSha);
        } else {
            $query->where(function ($q) use ($branchName) {
                $q->where('payload->branch_name', $branchName)
                    ->orWhere('commit_sha', 'HEAD');
            });
        }

        $recent = $query->first();
        if (! $recent) {
            return;
        }

        throw ValidationException::withMessages([
            'repository' => sprintf(
                'A recent review for this repository timed out during result submission (job #%d). '
                .'Wait %d minutes or cancel stuck jobs before Review Now to avoid a re-queue loop.',
                $recent->id,
                $cooldown
            ),
        ]);
    }

    private function findOpenReviewJob(
        Repository $repository,
        ?string $commitSha,
        ?string $branchName = null,
    ): ?ReviewJob {
        $query = ReviewJob::query()
            ->where('repository_id', $repository->id)
            ->whereIn('status', [
                ReviewJobStatus::Pending->value,
                ReviewJobStatus::Processing->value,
                ReviewJobStatus::Running->value,
            ])
            ->orderByDesc('id');

        if ($commitSha && $commitSha !== 'HEAD') {
            $query->where('commit_sha', $commitSha);
        } elseif ($branchName) {
            $query->where(function ($q) use ($branchName) {
                $q->where('payload->branch_name', $branchName)
                    ->orWhere('commit_sha', 'HEAD');
            });
        }

        return $query->first();
    }

    /**
     * Cancel extra pending duplicates for the same SHA/branch (keep none — caller creates the new job).
     */
    private function cancelPendingDuplicates(
        Repository $repository,
        string $commitSha,
        string $branchName,
    ): void {
        $query = ReviewJob::query()
            ->where('repository_id', $repository->id)
            ->where('status', ReviewJobStatus::Pending->value);

        if ($commitSha !== 'HEAD' && $commitSha !== '') {
            $query->where('commit_sha', $commitSha);
        } else {
            $query->where(function ($q) use ($branchName) {
                $q->where('payload->branch_name', $branchName)
                    ->orWhere('commit_sha', 'HEAD');
            });
        }

        $query->update([
            'status' => ReviewJobStatus::Cancelled->value,
            'completed_at' => now(),
            'error_message' => 'Cancelled as duplicate pending job before re-queue.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareRepositoryData(array $data): array
    {
        $reviewRulesJson = $data['review_rules_json'] ?? null;
        $notificationSettings = $data['notification_settings_json'] ?? null;

        $reviewRules = [];
        if (is_string($reviewRulesJson) && trim($reviewRulesJson) !== '') {
            $decoded = json_decode($reviewRulesJson, true, flags: JSON_THROW_ON_ERROR);
            $reviewRules = is_array($decoded) ? $decoded : [];
        }

        $reviewRules['trusted_authors'] = $this->parseTrustedAuthors($data['trusted_authors'] ?? null);

        return [
            ...Arr::only($data, [
                'project_id',
                'name',
                'full_name',
                'github_repo_id',
                'status',
                'is_paused',
                'default_branch',
                'trello_board_id',
                'trello_list_id',
            ]),
            'review_rules' => $reviewRules,
            'notification_settings' => $notificationSettings ? json_decode($notificationSettings, true, flags: JSON_THROW_ON_ERROR) : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function parseTrustedAuthors(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,;]+/', (string) $raw) ?: [];
        }

        return collect($parts)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function extractBranchNames(array $data): array
    {
        $rawBranches = (string) ($data['branches'] ?? '');

        return collect(preg_split('/\r\n|\r|\n/', $rawBranches) ?: [])
            ->map(fn(string $branch): string => trim($branch))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $branchNames
     */
    private function syncBranches(Repository $repository, array $branchNames): void
    {
        if ($branchNames === []) {
            $branchNames = [$repository->default_branch];
        }

        $repository->branches()
            ->whereNotIn('name', $branchNames)
            ->delete();

        $now = now();
        $rows = [];
        foreach ($branchNames as $branchName) {
            $rows[] = [
                'repository_id' => $repository->id,
                'name' => $branchName,
                'is_protected' => in_array($branchName, ['main', 'master', 'staging'], true),
                'review_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            \App\Models\Branch::query()->upsert(
                $rows,
                ['repository_id', 'name'],
                ['is_protected', 'review_enabled', 'updated_at']
            );
        }
    }
}
