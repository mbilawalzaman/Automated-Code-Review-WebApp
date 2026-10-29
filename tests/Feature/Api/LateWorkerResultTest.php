<?php

namespace Tests\Feature\Api;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LateWorkerResultTest extends TestCase
{
    use RefreshDatabase;

    protected string $workerToken = 'test_worker_bearer_token_999';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.worker.token' => $this->workerToken]);
    }

    public function test_rejects_result_submission_for_already_finalized_job_with_409_conflict(): void
    {
        $project = Project::factory()->create();
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'status' => RepositoryStatus::Active,
        ]);

        $failedJob = ReviewJob::query()->create([
            'repository_id' => $repo->id,
            'status' => ReviewJobStatus::Failed->value,
            'commit_sha' => 'abc1234',
            'started_at' => now()->subMinutes(40),
            'completed_at' => now()->subMinutes(10),
            'trigger_type' => 'manual',
            'idempotency_key' => 'test_late_job_1',
        ]);

        $payload = [
            'status' => 'passed',
            'summary' => 'Late worker submission attempt',
            'metrics' => ['files_analyzed' => 1],
        ];

        $response = $this->withToken($this->workerToken)
            ->postJson("/api/v1/worker/jobs/{$failedJob->id}/result", $payload);

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
        ]);
    }
}
