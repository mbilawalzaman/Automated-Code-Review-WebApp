<?php

namespace Tests\Feature\Api;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Branch;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubCheckRunServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Repository $repository;
    protected Branch $branch;
    protected string $workerToken = 'test-worker-token';
    protected string $webhookSecret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.worker.token' => $this->workerToken,
            'github.token' => 'ghp_fake_test_token_12345',
            'github.webhook_secret' => $this->webhookSecret,
        ]);

        $project = Project::factory()->create();
        $this->repository = Repository::factory()->create([
            'project_id' => $project->id,
            'name' => 'Automated-Code-Review-WebApp',
            'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
            'status' => RepositoryStatus::Active,
        ]);
        $this->branch = Branch::query()->create([
            'repository_id' => $this->repository->id,
            'name' => 'main',
            'is_default' => true,
        ]);
    }

    public function test_creates_pending_check_run_and_persists_check_run_id_on_webhook_event(): void
    {
        Http::fake([
            'https://api.github.com/repos/*/check-runs' => Http::response(['id' => 987654321], 201),
        ]);

        $webhookPayload = [
            'ref' => 'refs/heads/feature/login',
            'after' => 'abc1234567890abcdef1234567890abcdef1234',
            'repository' => [
                'name' => $this->repository->name,
                'full_name' => $this->repository->full_name,
                'html_url' => 'https://github.com/' . $this->repository->full_name,
            ],
            'pusher' => ['name' => 'Developer'],
        ];

        $jsonPayload = json_encode($webhookPayload);
        $signature = 'sha256=' . hash_hmac('sha256', $jsonPayload, $this->webhookSecret);

        $response = $this->call(
            'POST',
            route('github.webhook'),
            [],
            [],
            [],
            [
                'HTTP_X_GITHUB_EVENT' => 'push',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $jsonPayload
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('review_jobs', [
            'repository_id' => $this->repository->id,
            'commit_sha' => 'abc1234567890abcdef1234567890abcdef1234',
            'github_check_run_id' => '987654321',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/check-runs') &&
                   $request['status'] === 'in_progress';
        });
    }

    public function test_finalizes_check_run_and_posts_consolidated_pr_review_on_job_completion(): void
    {
        Http::fake([
            'https://api.github.com/repos/*/check-runs/987654321' => Http::response(['id' => 987654321], 200),
            'https://api.github.com/repos/*/pulls/15/reviews' => Http::response(['id' => 456], 200),
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'key-check-run-' . uniqid(),
            'status' => ReviewJobStatus::Processing->value,
            'commit_sha' => 'abc1234567890abcdef1234567890abcdef1234',
            'pull_request_number' => 15,
            'github_check_run_id' => '987654321',
            'trigger_type' => 'webhook_pull_request',
            'attempts' => 1,
        ]);

        $payload = [
            'status' => 'failed',
            'summary' => 'Security flaws detected by SAST tools.',
            'pull_request_title' => 'PR #15',
            'author' => 'Developer',
            'tools_used' => ['gitleaks', 'semgrep', 'osv-scanner'],
            'findings' => [
                [
                    'title' => 'AWS Secret Leaked',
                    'severity' => 'high',
                    'file_path' => 'config/aws.php',
                    'line_number' => 10,
                    'description' => 'AKIAIOSFODNN7EXAMPLE detected',
                    'tool_name' => 'gitleaks',
                ],
            ],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->workerToken,
            'Accept' => 'application/json',
        ])->postJson(route('worker.jobs.result', $job->id), $payload);

        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/check-runs/987654321') &&
                   $request['status'] === 'completed' &&
                   $request['conclusion'] === 'failure';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/pulls/15/reviews') &&
                   $request['event'] === 'REQUEST_CHANGES';
        });
    }

    public function test_graceful_fault_isolation_when_github_api_returns_500_error(): void
    {
        Http::fake([
            'https://api.github.com/*' => Http::response(['message' => 'Internal Server Error'], 500),
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'key-error-test-' . uniqid(),
            'status' => ReviewJobStatus::Processing->value,
            'commit_sha' => 'abc1234567890abcdef1234567890abcdef1234',
            'pull_request_number' => 15,
            'github_check_run_id' => '987654321',
            'trigger_type' => 'webhook_pull_request',
            'attempts' => 1,
        ]);

        $payload = [
            'status' => 'passed',
            'summary' => 'All checks passed cleanly.',
            'pull_request_title' => 'PR #15',
            'author' => 'Developer',
            'tools_used' => ['semgrep'],
            'findings' => [],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->workerToken,
            'Accept' => 'application/json',
        ])->postJson(route('worker.jobs.result', $job->id), $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('reviews', [
            'repository_id' => $this->repository->id,
            'review_job_id' => $job->id,
            'status' => 'passed',
        ]);
    }
}
