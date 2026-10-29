<?php

namespace Tests\Feature\Admin;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepositoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_repository_can_be_created_with_branches(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create();

        $response = $this->actingAs($user)->post(route('admin.repositories.store'), [
            'project_id' => $project->id,
            'name' => 'qa-worker',
            'full_name' => 'acme/qa-worker',
            'github_repo_id' => 12345,
            'status' => RepositoryStatus::Connected->value,
            'default_branch' => 'main',
            'branches' => "main\nstaging\nfeature/review",
            'trello_board_id' => 'board-123',
            'trello_list_id' => 'list-456',
            'review_rules_json' => '{"static_first":true}',
            'notification_settings_json' => '{"critical_email":true}',
        ]);

        $response->assertRedirect(route('admin.repositories.index'));

        $repository = Repository::query()->where('full_name', 'acme/qa-worker')->firstOrFail();

        $this->assertSame(RepositoryStatus::Connected, $repository->status);
        $this->assertSame([
            'static_first' => true,
            'trusted_authors' => [],
        ], $repository->review_rules);
        $this->assertDatabaseHas('branches', [
            'repository_id' => $repository->id,
            'name' => 'staging',
            'is_protected' => true,
        ]);
    }

    public function test_negative_github_repo_id_is_rejected_on_create(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create();

        $response = $this->actingAs($user)->from(route('admin.repositories.index'))->post(route('admin.repositories.store'), [
            'project_id' => $project->id,
            'name' => 'qa-worker',
            'full_name' => 'acme/qa-worker-negative-id',
            'github_repo_id' => -1,
            'status' => RepositoryStatus::Connected->value,
            'default_branch' => 'main',
            'branches' => 'main',
        ]);

        $response->assertRedirect(route('admin.repositories.index'));
        $response->assertSessionHasErrors(['github_repo_id']);
        $this->assertDatabaseMissing('repositories', [
            'full_name' => 'acme/qa-worker-negative-id',
        ]);
    }

    public function test_zero_github_repo_id_is_rejected_on_create(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create();

        $response = $this->actingAs($user)->from(route('admin.repositories.index'))->post(route('admin.repositories.store'), [
            'project_id' => $project->id,
            'name' => 'qa-worker',
            'full_name' => 'acme/qa-worker-zero-id',
            'github_repo_id' => 0,
            'status' => RepositoryStatus::Connected->value,
            'default_branch' => 'main',
            'branches' => 'main',
        ]);

        $response->assertRedirect(route('admin.repositories.index'));
        $response->assertSessionHasErrors(['github_repo_id']);
        $this->assertDatabaseMissing('repositories', [
            'full_name' => 'acme/qa-worker-zero-id',
        ]);
    }

    public function test_negative_github_repo_id_is_rejected_on_update(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'github_repo_id' => 12345,
        ]);

        $response = $this->actingAs($user)->from(route('admin.repositories.index'))->put(route('admin.repositories.update', $repository), [
            'project_id' => $repository->project_id,
            'name' => $repository->name,
            'full_name' => $repository->full_name,
            'github_repo_id' => -1,
            'status' => $repository->status->value,
            'default_branch' => $repository->default_branch ?? 'main',
            'branches' => 'main',
        ]);

        $response->assertRedirect(route('admin.repositories.index'));
        $response->assertSessionHasErrors(['github_repo_id']);
        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'github_repo_id' => 12345,
        ]);
    }

    public function test_repository_trusted_authors_are_stored_in_review_rules(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create();

        $response = $this->actingAs($user)->post(route('admin.repositories.store'), [
            'project_id' => $project->id,
            'name' => 'trusted-repo',
            'full_name' => 'acme/trusted-repo',
            'status' => RepositoryStatus::Connected->value,
            'default_branch' => 'main',
            'branches' => 'main',
            'trusted_authors' => 'Alice, bob; ACME-Bot',
            'review_rules_json' => '{"static_first":true}',
        ]);

        $response->assertRedirect(route('admin.repositories.index'));

        $repository = Repository::query()->where('full_name', 'acme/trusted-repo')->firstOrFail();
        $this->assertSame(['alice', 'bob', 'acme-bot'], $repository->review_rules['trusted_authors'] ?? null);
        $this->assertTrue($repository->review_rules['static_first'] ?? false);
    }

    public function test_updating_trusted_authors_overrides_json_copy(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'review_rules' => [
                'static_first' => true,
                'trusted_authors' => ['old-dev'],
            ],
        ]);

        $this->actingAs($user)->put(route('admin.repositories.update', $repository), [
            'project_id' => $repository->project_id,
            'name' => $repository->name,
            'full_name' => $repository->full_name,
            'status' => $repository->status->value,
            'default_branch' => $repository->default_branch ?? 'main',
            'branches' => 'main',
            'trusted_authors' => 'new-dev',
            'review_rules_json' => '{"static_first":true,"trusted_authors":["should-be-ignored"]}',
        ])->assertRedirect();

        $repository->refresh();
        $this->assertSame(['new-dev'], $repository->review_rules['trusted_authors'] ?? null);
        $this->assertTrue($repository->review_rules['static_first'] ?? false);
    }

    public function test_repository_status_controls_update_state(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'status' => RepositoryStatus::Connected,
            'is_paused' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.repositories.pause', $repository))
            ->assertRedirect();

        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'status' => RepositoryStatus::Paused->value,
            'is_paused' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.repositories.resume', $repository))
            ->assertRedirect();

        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'status' => RepositoryStatus::Active->value,
            'is_paused' => false,
        ]);
    }

    public function test_activate_queues_baseline_review_job(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'status' => RepositoryStatus::Connected,
            'is_paused' => false,
            'full_name' => 'acme/baseline-repo',
            'default_branch' => 'main',
        ]);

        $this->actingAs($user)
            ->withConfirmedPassword()
            ->patch(route('admin.repositories.activate', $repository))
            ->assertRedirect();

        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'status' => RepositoryStatus::Active->value,
        ]);

        $this->assertDatabaseHas('review_jobs', [
            'repository_id' => $repository->id,
            'trigger_type' => 'baseline_activation',
            'status' => ReviewJobStatus::Pending->value,
            'commit_sha' => 'HEAD',
        ]);

        // Second activate while already Active must not create another baseline job
        $this->actingAs($user)
            ->withConfirmedPassword()
            ->patch(route('admin.repositories.activate', $repository))
            ->assertRedirect();

        $this->assertSame(
            1,
            ReviewJob::where('repository_id', $repository->id)
                ->where('trigger_type', 'baseline_activation')
                ->count()
        );
    }

    public function test_activate_attempts_branch_protection_without_blocking_activation(): void
    {
        config(['github.token' => 'ghp_fake_token']);

        Http::fake([
            'https://api.github.com/repos/*/branches/*' => Http::response(['name' => 'main'], 200),
            'https://api.github.com/repos/*/branches/*/protection' => Http::sequence()
                ->push(['message' => 'Branch not protected'], 404)
                ->push(['url' => 'ok'], 200),
        ]);

        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'status' => RepositoryStatus::Connected,
            'full_name' => 'acme/protect-on-activate',
            'default_branch' => 'main',
        ]);
        \App\Models\Branch::query()->create([
            'repository_id' => $repository->id,
            'name' => 'main',
            'is_default' => true,
            'is_protected' => false,
        ]);

        $this->actingAs($user)
            ->withConfirmedPassword()
            ->patch(route('admin.repositories.activate', $repository))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'status' => RepositoryStatus::Active->value,
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/branches/main/protection'));
    }

    public function test_protect_branches_action_applies_rules(): void
    {
        config(['github.token' => 'ghp_fake_token']);

        Http::fake([
            'https://api.github.com/repos/*/branches/main' => Http::response(['name' => 'main'], 200),
            'https://api.github.com/repos/*/branches/main/protection' => Http::sequence()
                ->push(['message' => 'Branch not protected'], 404)
                ->push(['url' => 'ok'], 200),
        ]);

        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'status' => RepositoryStatus::Active,
            'full_name' => 'acme/manual-protect',
            'default_branch' => 'main',
        ]);
        \App\Models\Branch::query()->create([
            'repository_id' => $repository->id,
            'name' => 'main',
            'is_default' => true,
            'is_protected' => false,
        ]);

        $this->actingAs($user)
            ->post(route('admin.repositories.protect-branches', $repository))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('branches', [
            'repository_id' => $repository->id,
            'name' => 'main',
            'is_protected' => true,
        ]);
    }

    public function test_protect_branches_rejects_disconnected_repository(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'status' => RepositoryStatus::Disconnected,
        ]);

        $this->actingAs($user)
            ->from(route('admin.repositories.index'))
            ->post(route('admin.repositories.protect-branches', $repository))
            ->assertRedirect(route('admin.repositories.index'))
            ->assertSessionHasErrors('repository');
    }

    public function test_review_now_queues_manual_review_job(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'status' => RepositoryStatus::Active,
            'is_paused' => false,
            'full_name' => 'acme/review-now-repo',
            'default_branch' => 'main',
        ]);

        $this->actingAs($user)
            ->post(route('admin.repositories.review-now', $repository))
            ->assertRedirect();

        $this->assertDatabaseHas('review_jobs', [
            'repository_id' => $repository->id,
            'trigger_type' => 'manual_review_now',
            'status' => ReviewJobStatus::Pending->value,
        ]);
    }

    public function test_review_now_rejects_paused_repository(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create([
            'status' => RepositoryStatus::Paused,
            'is_paused' => true,
        ]);

        $this->actingAs($user)
            ->from(route('admin.repositories.index'))
            ->post(route('admin.repositories.review-now', $repository))
            ->assertRedirect(route('admin.repositories.index'))
            ->assertSessionHasErrors('repository');

        $this->assertSame(0, ReviewJob::where('repository_id', $repository->id)->count());
    }
}
