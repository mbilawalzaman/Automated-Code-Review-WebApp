<?php

namespace Tests\Feature\Admin;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SweepStuckJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sweeps_jobs_stuck_in_processing_state_longer_than_30_minutes(): void
    {
        $project = Project::factory()->create();
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'status' => RepositoryStatus::Active,
        ]);

        $stuckJob = ReviewJob::query()->create([
            'repository_id' => $repo->id,
            'status' => ReviewJobStatus::Processing->value,
            'commit_sha' => 'abc1234',
            'started_at' => Carbon::now()->subMinutes(35),
            'trigger_type' => 'manual',
            'idempotency_key' => 'test_stuck_1',
        ]);

        $activeJob = ReviewJob::query()->create([
            'repository_id' => $repo->id,
            'status' => ReviewJobStatus::Processing->value,
            'commit_sha' => 'def5678',
            'started_at' => Carbon::now()->subMinutes(10),
            'trigger_type' => 'manual',
            'idempotency_key' => 'test_active_1',
        ]);

        $this->artisan('jobs:sweep-stuck')->assertExitCode(0);

        $stuckJob->refresh();
        $activeJob->refresh();

        $this->assertEquals(ReviewJobStatus::Failed, $stuckJob->status);
        $this->assertEquals(ReviewJobStatus::Processing, $activeJob->status);

        $this->assertDatabaseHas('reviews', [
            'review_job_id' => $stuckJob->id,
            'status' => ReviewStatus::ReviewError->value,
        ]);
    }
}
