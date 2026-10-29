<?php

namespace Tests\Feature\Admin;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Models\User;
use App\Services\Admin\RepositoryService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SameShaRequeueGuardTest extends TestCase
{
    public function test_review_now_coalesces_onto_open_processing_job(): void
    {
        [$user, $repository] = $this->seedRepo();

        $open = ReviewJob::create([
            'repository_id' => $repository->id,
            'trigger_type' => 'manual_review_now',
            'commit_sha' => 'HEAD',
            'idempotency_key' => 'open_job_key',
            'status' => ReviewJobStatus::Processing->value,
            'started_at' => now(),
            'payload' => [
                'event' => 'manual_review_now',
                'branch_name' => 'main',
            ],
        ]);

        $this->actingAs($user);
        $job = app(RepositoryService::class)->reviewNow($repository->id, 'main');

        $this->assertSame($open->id, $job->id);
        $this->assertSame(1, ReviewJob::where('repository_id', $repository->id)->count());
    }

    public function test_review_now_blocked_during_submission_timeout_cooldown(): void
    {
        [$user, $repository] = $this->seedRepo();

        ReviewJob::create([
            'repository_id' => $repository->id,
            'trigger_type' => 'manual_review_now',
            'commit_sha' => 'HEAD',
            'idempotency_key' => 'failed_timeout_key',
            'status' => ReviewJobStatus::Failed->value,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(2),
            'error_message' => 'result_submission_timeout: Job timed out after 30 minutes in processing state.',
            'payload' => [
                'event' => 'manual_review_now',
                'branch_name' => 'main',
            ],
        ]);

        $this->actingAs($user);

        $this->expectException(ValidationException::class);
        app(RepositoryService::class)->reviewNow($repository->id, 'main');
    }

    /**
     * @return array{0: User, 1: Repository}
     */
    private function seedRepo(): array
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $project = Project::create([
            'name' => 'Requeue Guard Project',
            'slug' => 'requeue-guard-project',
            'description' => 'Same-SHA guard tests',
        ]);

        $repository = Repository::create([
            'project_id' => $project->id,
            'name' => 'requeue-repo',
            'full_name' => 'AlwaysWinningForever/requeue-repo',
            'default_branch' => 'main',
            'status' => RepositoryStatus::Active->value,
        ]);

        return [$user, $repository];
    }
}
