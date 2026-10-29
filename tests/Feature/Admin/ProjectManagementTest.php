<?php

namespace Tests\Feature\Admin;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_project_can_be_created_from_admin_panel(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->post(route('admin.projects.store'), [
            'name' => 'Payments Platform',
            'slug' => 'payments-platform',
            'description' => 'Review automation for payments repositories.',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.projects.index'));

        $this->assertDatabaseHas('projects', [
            'name' => 'Payments Platform',
            'slug' => 'payments-platform',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    public function test_project_can_be_updated_from_admin_panel(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->put(route('admin.projects.update', $project), [
            'name' => 'Updated Project',
            'slug' => 'updated-project',
            'description' => 'Updated description.',
        ]);

        $response->assertRedirect(route('admin.projects.index'));

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated Project',
            'slug' => 'updated-project',
            'is_active' => false,
        ]);
    }

    public function test_duplicate_project_name_is_rejected_on_create(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        Project::factory()->create(['name' => 'Aloha Health', 'slug' => 'aloha-health']);

        $response = $this->actingAs($user)->from(route('admin.projects.index'))->post(route('admin.projects.store'), [
            'name' => 'Aloha Health',
            'slug' => 'aloha-health-2',
            'description' => 'Duplicate name should fail.',
            'is_active' => '1',
            '_modal' => 'project-create',
        ]);

        $response->assertRedirect(route('admin.projects.index'));
        $response->assertSessionHasErrors(['name']);
        $this->assertSame(1, Project::query()->where('name', 'Aloha Health')->count());
    }

    public function test_duplicate_project_name_is_rejected_on_update(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        Project::factory()->create(['name' => 'Aloha Health', 'slug' => 'aloha-health']);
        $other = Project::factory()->create(['name' => 'Other Project', 'slug' => 'other-project']);

        $response = $this->actingAs($user)->from(route('admin.projects.index'))->put(route('admin.projects.update', $other), [
            'name' => 'Aloha Health',
            'slug' => 'other-project',
            'description' => 'Should not steal existing name.',
            '_modal' => 'project-edit-'.$other->id,
        ]);

        $response->assertRedirect(route('admin.projects.index'));
        $response->assertSessionHasErrors(['name']);
        $this->assertDatabaseHas('projects', [
            'id' => $other->id,
            'name' => 'Other Project',
        ]);
    }

    public function test_project_can_keep_its_own_name_on_update(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['name' => 'Aloha Health', 'slug' => 'aloha-health']);

        $response = $this->actingAs($user)->put(route('admin.projects.update', $project), [
            'name' => 'Aloha Health',
            'slug' => 'aloha-health',
            'description' => 'Same name is allowed for the same project.',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.projects.index'));
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Aloha Health',
            'description' => 'Same name is allowed for the same project.',
        ]);
    }

    public function test_projects_datatable_sorts_by_name(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        Project::factory()->create(['name' => 'Zebra Project', 'slug' => 'zebra-project']);
        Project::factory()->create(['name' => 'Alpha Project', 'slug' => 'alpha-project']);

        $asc = $this->actingAs($user)->getJson(route('admin.projects.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'slug', 'name' => 'slug', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'description', 'name' => 'description', 'searchable' => 'true', 'orderable' => 'false'],
                ['data' => 'repositories_count', 'name' => 'repositories_count', 'searchable' => 'false', 'orderable' => 'true'],
                ['data' => 'status_formatted', 'name' => 'is_active', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false'],
            ],
        ]));

        $asc->assertOk();
        $ascNames = collect($asc->json('data'))->map(fn (array $row) => strip_tags($row['name']))->values();
        $this->assertSame('Alpha Project', $ascNames->first());

        $desc = $this->actingAs($user)->getJson(route('admin.projects.data', [
            'draw' => 2,
            'start' => 0,
            'length' => 25,
            'order' => [['column' => 0, 'dir' => 'desc']],
            'columns' => [
                ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'slug', 'name' => 'slug', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'description', 'name' => 'description', 'searchable' => 'true', 'orderable' => 'false'],
                ['data' => 'repositories_count', 'name' => 'repositories_count', 'searchable' => 'false', 'orderable' => 'true'],
                ['data' => 'status_formatted', 'name' => 'is_active', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false'],
            ],
        ]));

        $desc->assertOk();
        $descNames = collect($desc->json('data'))->map(fn (array $row) => strip_tags($row['name']))->values();
        $this->assertSame('Zebra Project', $descNames->first());
    }
}
