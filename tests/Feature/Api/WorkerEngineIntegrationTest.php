<?php

namespace Tests\Feature\Api;

use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Models\Branch;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Repository $repository;
    protected Branch $branch;
    protected string $workerToken = 'test-worker-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.worker.token' => $this->workerToken]);

        $project = Project::factory()->create();
        $this->repository = Repository::factory()->create(['project_id' => $project->id]);
        $this->branch = Branch::query()->create([
            'repository_id' => $this->repository->id,
            'name' => 'main',
            'is_default' => true,
        ]);
    }

    public function test_worker_can_claim_pending_job(): void
    {
        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'key-claim-test-' . uniqid(),
            'status' => ReviewJobStatus::Pending->value,
            'commit_sha' => '1234567890abcdef1234567890abcdef12345678',
            'trigger_type' => 'manual',
            'attempts' => 0,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->workerToken,
            'Accept' => 'application/json',
        ])->postJson(route('worker.jobs.next'));

        $response->assertStatus(200);
        $response->assertJson([
            'has_job' => true,
            'job' => [
                'id' => $job->id,
                'repository_id' => $this->repository->id,
            ],
        ]);

        $this->assertDatabaseHas('review_jobs', [
            'id' => $job->id,
            'status' => ReviewJobStatus::Processing->value,
            'attempts' => 1,
        ]);
    }

    public function test_worker_can_submit_multi_tool_sast_results(): void
    {
        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'key-submit-test-' . uniqid(),
            'status' => ReviewJobStatus::Processing->value,
            'commit_sha' => '1234567890abcdef1234567890abcdef12345678',
            'trigger_type' => 'manual',
            'attempts' => 1,
        ]);

        $payload = [
            'status' => 'failed',
            'summary' => 'Automated review completed with 2 findings.',
            'pull_request_title' => 'Feature PR #12',
            'author' => 'Developer',
            'tools_used' => ['gitleaks', 'semgrep', 'osv-scanner', 'ollama/qwen2.5-coder:7b'],
            'findings' => [
                [
                    'title' => 'Hardcoded Secret Leaked: AWS Access Key',
                    'severity' => 'high',
                    'file_path' => 'config/aws.php',
                    'line_start' => 15,
                    'line_number' => 15,
                    'description' => 'AKIAIOSFODNN7EXAMPLE detected in code.',
                    'tool_name' => 'gitleaks',
                    'rule_id' => 'aws-access-token',
                    'remediation' => 'Move AWS key to environment variables.',
                ],
                [
                    'title' => 'SAST Finding: Sql Injection',
                    'severity' => 'critical',
                    'file_path' => 'app/Services/UserService.php',
                    'line_start' => 42,
                    'line_number' => 42,
                    'description' => 'Unsanitized user input passed to raw SQL query.',
                    'tool_name' => 'semgrep',
                    'rule_id' => 'php.sql-injection',
                    'remediation' => 'Use parameterized PDO queries.',
                ],
            ],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->workerToken,
            'Accept' => 'application/json',
        ])->postJson(route('worker.jobs.result', $job->id), $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'findings_count' => 2,
        ]);

        $this->assertDatabaseHas('reviews', [
            'repository_id' => $this->repository->id,
            'review_job_id' => $job->id,
            'status' => ReviewStatus::Failed->value,
            'pull_request_title' => 'Feature PR #12',
        ]);

        $this->assertDatabaseHas('findings', [
            'file_path' => 'config/aws.php',
            'line_number' => 15,
            'severity' => 'high',
            'source' => 'gitleaks',
        ]);

        $this->assertDatabaseHas('findings', [
            'file_path' => 'app/Services/UserService.php',
            'line_number' => 42,
            'severity' => 'critical',
            'source' => 'semgrep',
        ]);

        $this->assertDatabaseHas('reports', [
            'repository_id' => $this->repository->id,
            'type' => 'review_summary',
            'format' => 'pdf',
        ]);

        $this->assertDatabaseHas('review_jobs', [
            'id' => $job->id,
            'status' => ReviewJobStatus::Failed->value,
        ]);
    }
}
