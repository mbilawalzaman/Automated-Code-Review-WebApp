<?php

namespace Tests\Feature\Api;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Models\User;
use App\Services\Api\ExecutionAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverageIngestTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->token = (string) config('services.worker.token');
        config(['worker.changed_coverage_min_percent' => 70]);
    }

    public function test_coverage_metrics_are_persisted_on_review(): void
    {
        $job = $this->makeJob();

        $response = $this->withToken($this->token)->postJson("/api/v1/worker/jobs/{$job->id}/result", [
            'status' => 'passed',
            'summary' => 'Tests passed with coverage',
            'author' => 'alice',
            'tools_used' => ['phpunit'],
            'coverage_percentage' => 88.5,
            'changed_code_coverage' => 92.0,
            'execution_authorized' => true,
            'stacks' => ['php'],
            'test_summary' => [
                'executed' => true,
                'framework' => 'phpunit',
                'passed' => true,
            ],
            'findings' => [],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('reviews', [
            'review_job_id' => $job->id,
            'coverage_percentage' => 88.50,
            'changed_code_coverage' => 92.00,
            'execution_authorized' => 1,
        ]);
    }

    public function test_low_changed_coverage_blocks_merge_with_tst004_finding(): void
    {
        $job = $this->makeJob();

        $response = $this->withToken($this->token)->postJson("/api/v1/worker/jobs/{$job->id}/result", [
            'status' => 'passed',
            'summary' => 'Coverage too low',
            'author' => 'alice',
            'tools_used' => ['phpunit'],
            'coverage_percentage' => 80.0,
            'changed_code_coverage' => 40.0,
            'execution_authorized' => true,
            'stacks' => ['php'],
            'test_summary' => ['executed' => true, 'framework' => 'phpunit', 'passed' => true],
            'findings' => [],
        ]);

        $response->assertOk();

        $review = $job->fresh()->review ?? \App\Models\Review::query()->where('review_job_id', $job->id)->first();
        $this->assertNotNull($review);
        $this->assertSame(ReviewStatus::Failed, $review->status);
        $this->assertTrue(
            Finding::query()->where('review_id', $review->id)->where('rule_id', 'TST-004')->where('is_blocking', true)->exists()
        );
    }

    protected function makeJob(): ReviewJob
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'status' => RepositoryStatus::Active,
        ]);

        return ReviewJob::query()->create([
            'repository_id' => $repository->id,
            'status' => ReviewJobStatus::Processing,
            'trigger_type' => 'webhook_push',
            'idempotency_key' => 'coverage-'.uniqid(),
            'commit_sha' => str_repeat('c', 40),
            'started_at' => now(),
            'payload' => ['author' => 'alice', 'branch_name' => 'feature/x'],
        ]);
    }
}
