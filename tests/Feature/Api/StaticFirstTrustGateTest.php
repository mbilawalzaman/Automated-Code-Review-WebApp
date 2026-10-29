<?php

namespace Tests\Feature\Api;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Services\Api\ExecutionAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaticFirstTrustGateTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->token = (string) config('services.worker.token');
        config([
            'worker.trusted_authors' => ['trusted-dev'],
            'worker.authorize_pm_triggered_jobs' => true,
        ]);
    }

    public function test_untrusted_webhook_jobs_are_not_execution_authorized(): void
    {
        $job = $this->makeJob('webhook_push', 'random-contributor');

        $decision = app(ExecutionAuthorizationService::class)->forJob($job);

        $this->assertFalse($decision['authorized']);
        $this->assertSame('static_first_untrusted', $decision['reason']);
    }

    public function test_trusted_author_is_execution_authorized(): void
    {
        $job = $this->makeJob('webhook_push', 'trusted-dev');

        $decision = app(ExecutionAuthorizationService::class)->forJob($job);

        $this->assertTrue($decision['authorized']);
        $this->assertSame('trusted_author', $decision['reason']);
    }

    public function test_manual_review_now_is_execution_authorized(): void
    {
        $job = $this->makeJob('manual_review_now', 'anyone');

        $decision = app(ExecutionAuthorizationService::class)->forJob($job);

        $this->assertTrue($decision['authorized']);
        $this->assertStringContainsString('pm_triggered', $decision['reason']);
    }

    public function test_claim_payload_includes_execution_authorized_flag(): void
    {
        $this->makeJob('webhook_push', 'random-contributor');

        $response = $this->withToken($this->token)->postJson('/api/v1/worker/jobs/next');

        $response->assertOk()
            ->assertJsonPath('has_job', true)
            ->assertJsonPath('job.execution_authorized', false)
            ->assertJsonPath('job.execution_authorization_reason', 'static_first_untrusted');
    }

    public function test_repository_trusted_author_is_execution_authorized(): void
    {
        config(['worker.trusted_authors' => []]);

        $project = Project::factory()->create();
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'status' => RepositoryStatus::Active,
            'review_rules' => [
                'trusted_authors' => ['repo-dev'],
            ],
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $repository->id,
            'status' => ReviewJobStatus::Pending,
            'trigger_type' => 'webhook_push',
            'idempotency_key' => 'trust-repo-'.uniqid(),
            'commit_sha' => str_repeat('e', 40),
            'payload' => [
                'author' => 'repo-dev',
                'branch_name' => 'feature/trust',
            ],
        ]);

        $decision = app(ExecutionAuthorizationService::class)->forJob($job);

        $this->assertTrue($decision['authorized']);
        $this->assertSame('repository_trusted_author', $decision['reason']);
    }

    protected function makeJob(string $trigger, string $author): ReviewJob
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'status' => RepositoryStatus::Active,
        ]);

        return ReviewJob::query()->create([
            'repository_id' => $repository->id,
            'status' => ReviewJobStatus::Pending,
            'trigger_type' => $trigger,
            'idempotency_key' => 'trust-'.uniqid(),
            'commit_sha' => str_repeat('d', 40),
            'payload' => [
                'author' => $author,
                'branch_name' => 'feature/trust',
                'title' => 'Trust gate fixture',
            ],
        ]);
    }
}
