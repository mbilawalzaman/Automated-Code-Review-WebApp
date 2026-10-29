<?php

namespace App\Services\Api;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Repositories\Contracts\RepositoryRepositoryInterface;
use App\Repositories\Contracts\ReviewJobRepositoryInterface;
use Illuminate\Support\Str;

class GitHubWebhookService
{
    public function __construct(
        protected RepositoryRepositoryInterface $repositoryRepository,
        protected ReviewJobRepositoryInterface $reviewJobRepository,
        protected GitHubCheckRunService $checkRunService
    ) {
    }

    /**
     * Handle incoming GitHub webhook payload.
     *
     * @param  string  $event
     * @param  array  $payload
     * @return array
     */
    public function handleWebhook(string $event, array $payload): array
    {
        if (!in_array($event, ['push', 'pull_request'], true)) {
            return [
                'handled' => false,
                'message' => "Event type '{$event}' ignored",
                'job_id' => null,
            ];
        }

        $repoUrl = $payload['repository']['html_url'] ?? $payload['repository']['clone_url'] ?? null;
        $repoFullName = $payload['repository']['full_name'] ?? null;

        if (!$repoUrl && !$repoFullName) {
            return [
                'handled' => false,
                'message' => 'Missing repository identifier in webhook payload',
                'job_id' => null,
            ];
        }

        $repository = $this->findMatchingRepository($repoUrl, $repoFullName);

        if (!$repository) {
            return [
                'handled' => false,
                'message' => 'No matching registered repository found',
                'job_id' => null,
            ];
        }

        if (in_array($repository->status, [RepositoryStatus::Disconnected, RepositoryStatus::Paused], true)) {
            return [
                'handled' => false,
                'message' => 'Repository is currently inactive',
                'job_id' => null,
            ];
        }

        $eventData = $this->extractEventData($event, $payload);

        if (empty($eventData['commit_sha'])) {
            return [
                'handled' => false,
                'message' => 'Unable to determine commit SHA from payload',
                'job_id' => null,
            ];
        }

        $idempotencyKey = sha1($repository->id . '_' . $eventData['commit_sha'] . '_' . ($eventData['pr_number'] ?? $eventData['branch_name']));

        $existingJob = ReviewJob::where('idempotency_key', $idempotencyKey)->first();

        if ($existingJob) {
            return [
                'handled' => true,
                'message' => 'Review job already queued',
                'job_id' => $existingJob->id,
            ];
        }

        $reviewJob = $this->reviewJobRepository->create([
            'repository_id' => $repository->id,
            'trigger_type' => 'webhook_' . $event,
            'commit_sha' => $eventData['commit_sha'],
            'pull_request_number' => $eventData['pr_number'],
            'idempotency_key' => $idempotencyKey,
            'status' => ReviewJobStatus::Pending->value,
            'payload' => json_encode([
                'event' => $event,
                'branch_name' => $eventData['branch_name'],
                'author' => $eventData['author'],
                'pull_request_number' => $eventData['pr_number'],
                'raw' => $payload,
            ]),
        ]);

        // Create pending Check Run on GitHub and persist check run ID
        $checkRunId = $this->checkRunService->createPendingCheckRun($repository, $eventData['commit_sha']);
        if ($checkRunId) {
            $reviewJob->update(['github_check_run_id' => $checkRunId]);
        }

        return [
            'handled' => true,
            'message' => 'Review job successfully queued',
            'job_id' => $reviewJob->id,
            'github_check_run_id' => $checkRunId,
        ];
    }

    /**
     * Match database repository by exact full_name or URL path.
     * Never falls back to an unrelated repository.
     */
    protected function findMatchingRepository(?string $url, ?string $fullName): ?Repository
    {
        if ($fullName) {
            $repo = Repository::where('full_name', $fullName)->first();

            if ($repo) {
                return $repo;
            }
        }

        if ($url) {
            $path = parse_url($url, PHP_URL_PATH);
            $cleanFullName = trim((string) $path, '/');
            $cleanFullName = Str::before($cleanFullName, '.git');

            if ($cleanFullName) {
                $repo = Repository::where('full_name', $cleanFullName)->first();

                if ($repo) {
                    return $repo;
                }
            }
        }

        return null;
    }

    /**
     * Extract relevant SHA, branch, and metadata from GitHub payload.
     */
    protected function extractEventData(string $event, array $payload): array
    {
        if ($event === 'push') {
            $ref = $payload['ref'] ?? '';
            $branch = Str::after($ref, 'refs/heads/');
            $sha = $payload['after'] ?? $payload['head_commit']['id'] ?? null;
            $author = $payload['pusher']['name'] ?? $payload['head_commit']['author']['name'] ?? 'Unknown';

            return [
                'commit_sha' => $sha,
                'branch_name' => $branch,
                'author' => $author,
                'pr_number' => null,
            ];
        }

        if ($event === 'pull_request') {
            $sha = $payload['pull_request']['head']['sha'] ?? null;
            $branch = $payload['pull_request']['head']['ref'] ?? 'unknown';
            $author = $payload['pull_request']['user']['login'] ?? 'Unknown';
            $prNumber = $payload['number'] ?? null;

            return [
                'commit_sha' => $sha,
                'branch_name' => $branch,
                'author' => $author,
                'pr_number' => $prNumber,
            ];
        }

        return [
            'commit_sha' => null,
            'branch_name' => null,
            'author' => null,
            'pr_number' => null,
        ];
    }
}
