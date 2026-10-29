<?php

namespace Tests\Feature\Api;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;

use Tests\TestCase;

class WorkerApiTest extends TestCase
{

    protected Repository $repository;

    protected string $workerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerToken = (string) config('services.worker.token');

        $project = Project::create([
            'name' => 'Worker Test Project',
            'slug' => 'worker-test-project',
            'description' => 'Test project for Worker API integration',
        ]);

        $this->repository = Repository::create([
            'project_id' => $project->id,
            'name' => 'Automated-Code-Review-WebApp',
            'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
            'url' => 'https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp',
            'default_branch' => 'main',
            'status' => RepositoryStatus::Active->value,
        ]);
    }

    public function test_unauthenticated_worker_request_returns_unauthorized(): void
    {
        $response = $this->postJson('/api/v1/worker/jobs/next');

        $response->assertUnauthorized()
            ->assertJson([
                'error' => 'Unauthorized worker token',
            ]);
    }

    public function test_invalid_bearer_token_returns_unauthorized(): void
    {
        $response = $this->postJson('/api/v1/worker/jobs/next', [], [
            'Authorization' => 'Bearer invalid_worker_token',
        ]);

        $response->assertUnauthorized()
            ->assertJson([
                'error' => 'Unauthorized worker token',
            ]);
    }

    public function test_worker_receives_no_content_when_no_pending_jobs_exist(): void
    {
        $response = $this->postJson('/api/v1/worker/jobs/next', [], [
            'Authorization' => 'Bearer ' . $this->workerToken,
        ]);

        $response->assertStatus(204);
    }

    public function test_worker_claims_next_pending_job_and_updates_status(): void
    {
        $job = ReviewJob::create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_push',
            'commit_sha' => '12bdcbb1234567890abcdef1234567890abcdef1',
            'idempotency_key' => 'unique_key_123',
            'status' => ReviewJobStatus::Pending->value,
            'payload' => json_encode(['event' => 'push', 'pusher' => 'mbilawalzaman']),
        ]);

        $response = $this->postJson('/api/v1/worker/jobs/next', [], [
            'Authorization' => 'Bearer ' . $this->workerToken,
        ]);

        $response->assertOk()
            ->assertJson([
                'has_job' => true,
                'job' => [
                    'id' => $job->id,
                    'commit_sha' => '12bdcbb1234567890abcdef1234567890abcdef1',
                    'repository' => [
                        'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
                        'default_branch' => 'main',
                        'clone_url' => 'https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp.git',
                        'html_url' => 'https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('review_jobs', [
            'id' => $job->id,
            'status' => ReviewJobStatus::Processing->value,
        ]);
    }

    public function test_worker_can_submit_review_result_and_findings(): void
    {
        $job = ReviewJob::create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_pull_request',
            'commit_sha' => '0445c92abcdef1234567890abcdef1234567890',
            'pull_request_number' => 42,
            'idempotency_key' => 'unique_key_456',
            'status' => ReviewJobStatus::Processing->value,
            'payload' => json_encode(['event' => 'pull_request']),
        ]);

        $resultPayload = [
            'status' => ReviewStatus::PassedWithWarnings->value,
            'pull_request_title' => 'Feature: Worker Integration API',
            'author' => 'AI Review Worker Node',
            'summary' => 'Automated review finished with 1 warning finding.',
            'tools_used' => ['phpstan', 'pest', 'custom-srs-checker'],
            'findings' => [
                [
                    'severity' => FindingSeverity::Medium->value,
                    'status' => FindingStatus::Open->value,
                    'file_path' => 'app/Services/Api/WorkerService.php',
                    'line_number' => 45,
                    'rule_id' => 'SEC-002',
                    'title' => 'Missing parameter sanitization',
                    'evidence' => 'Parameter passed without sanitization.',
                    'recommendation' => 'Sanitize parameter before query execution.',
                    'is_blocking' => false,
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/v1/worker/jobs/{$job->id}/result",
            $resultPayload,
            ['Authorization' => 'Bearer ' . $this->workerToken]
        );

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'findings_count' => 1,
            ]);

        $this->assertDatabaseHas('review_jobs', [
            'id' => $job->id,
            'status' => ReviewJobStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('reviews', [
            'review_job_id' => $job->id,
            'status' => ReviewStatus::PassedWithWarnings->value,
            'commit_sha' => '0445c92abcdef1234567890abcdef1234567890',
        ]);

        $this->assertDatabaseHas('findings', [
            'file_path' => 'app/Services/Api/WorkerService.php',
            'line_number' => 45,
            'title' => 'Missing parameter sanitization',
        ]);
    }

    public function test_critical_finding_is_marked_blocking_when_flag_omitted(): void
    {
        $job = ReviewJob::create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_pull_request',
            'commit_sha' => 'abc123blockingtest0000000000000000000001',
            'pull_request_number' => 99,
            'idempotency_key' => 'unique_key_blocking',
            'status' => ReviewJobStatus::Processing->value,
            'payload' => json_encode(['event' => 'pull_request']),
        ]);

        $resultPayload = [
            'status' => ReviewStatus::Failed->value,
            'pull_request_title' => 'Security fix',
            'author' => 'Worker',
            'summary' => 'Critical finding detected.',
            'tools_used' => ['gitleaks'],
            'findings' => [
                [
                    'severity' => FindingSeverity::Critical->value,
                    'status' => FindingStatus::Open->value,
                    'file_path' => '.env',
                    'line_number' => 1,
                    'rule_id' => 'secret',
                    'title' => 'Hardcoded secret',
                    'evidence' => 'API key in source',
                    'recommendation' => 'Remove secret',
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/v1/worker/jobs/{$job->id}/result",
            $resultPayload,
            ['Authorization' => 'Bearer ' . $this->workerToken]
        );

        $response->assertOk();

        $this->assertDatabaseHas('findings', [
            'title' => 'Hardcoded secret',
            'severity' => FindingSeverity::Critical->value,
            'is_blocking' => true,
        ]);
    }
}
