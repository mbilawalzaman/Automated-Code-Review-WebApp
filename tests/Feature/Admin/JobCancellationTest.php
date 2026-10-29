<?php

namespace Tests\Feature\Admin;

use App\Enums\ReviewJobStatus;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobCancellationTest extends TestCase
{
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = User::factory()->superAdmin()->create(['email_verified_at' => now()]);
    }

    public function test_admin_can_cancel_pending_job(): void
    {
        $repo = Repository::factory()->create();
        $job = ReviewJob::query()->create([
            'repository_id' => $repo->id,
            'status' => ReviewJobStatus::Pending,
            'trigger_type' => 'manual',
            'commit_sha' => str_repeat('a', 40),
            'idempotency_key' => 'cancel-'.Str::uuid(),
            'payload' => [],
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.review-jobs.cancel', $job), ['reason' => 'Stuck in queue'])
            ->assertRedirect();

        $job->refresh();
        $this->assertSame(ReviewJobStatus::Cancelled, $job->status);
        $this->assertSame('Stuck in queue', $job->error_message);
        $this->assertNotNull($job->completed_at);
    }

    public function test_cannot_cancel_completed_job(): void
    {
        $repo = Repository::factory()->create();
        $job = ReviewJob::query()->create([
            'repository_id' => $repo->id,
            'status' => ReviewJobStatus::Completed,
            'trigger_type' => 'manual',
            'commit_sha' => str_repeat('b', 40),
            'idempotency_key' => 'cancel-done-'.Str::uuid(),
            'payload' => [],
            'completed_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.review-jobs.cancel', $job))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ReviewJobStatus::Completed, $job->fresh()->status);
    }

    public function test_late_worker_result_returns_409_after_cancel(): void
    {
        config(['services.worker.token' => 'worker-test-token']);

        $repo = Repository::factory()->create();
        $job = ReviewJob::query()->create([
            'repository_id' => $repo->id,
            'status' => ReviewJobStatus::Cancelled,
            'trigger_type' => 'manual',
            'commit_sha' => str_repeat('c', 40),
            'idempotency_key' => 'cancel-late-'.Str::uuid(),
            'payload' => [],
            'completed_at' => now(),
            'error_message' => 'Cancelled',
        ]);

        $this->withToken('worker-test-token')
            ->postJson("/api/v1/worker/jobs/{$job->id}/result", [
                'status' => 'passed',
                'summary' => 'late',
                'findings' => [],
            ])
            ->assertStatus(409);
    }
}
