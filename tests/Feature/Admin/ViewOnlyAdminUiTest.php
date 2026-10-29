<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;

class ViewOnlyAdminUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function userWithPermissions(array $permissionNames): User
    {
        $role = Role::query()->create([
            'name' => 'custom_'.str()->random(8),
            'display_name' => 'Custom Test Role',
            'description' => 'Permission-scoped UI test role',
            'is_system' => false,
        ]);
        $role->syncPermissions($permissionNames);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => UserRole::ProjectManager,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_projects_view_only_user_does_not_see_create_or_manage_actions(): void
    {
        $viewer = $this->userWithPermissions(['projects.view']);
        Project::factory()->create(['name' => 'Visible Project']);

        $this->actingAs($viewer)
            ->get(route('admin.projects.index'))
            ->assertOk()
            ->assertDontSee('Create Project', false);

        $response = $this->actingAs($viewer)->getJson(route('admin.projects.data', [
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

        $response->assertOk();
        $actions = (string) ($response->json('data.0.actions') ?? '');
        $this->assertStringNotContainsString('Edit', $actions);
        $this->assertStringContainsString('—', $actions);
    }

    public function test_repositories_view_only_user_does_not_see_create_or_manage_actions(): void
    {
        $viewer = $this->userWithPermissions(['repositories.view']);
        $project = Project::factory()->create();
        Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'acme/view-only-repo',
        ]);

        $this->actingAs($viewer)
            ->get(route('admin.repositories.index'))
            ->assertOk()
            ->assertDontSee('Add Repository', false);

        $response = $this->actingAs($viewer)->getJson(route('admin.repositories.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'repository_formatted', 'name' => 'full_name', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'default_branch_formatted', 'name' => 'default_branch', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'project', 'name' => 'project.name', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'branches_formatted', 'name' => 'branches', 'searchable' => 'false', 'orderable' => 'false'],
                ['data' => 'status_formatted', 'name' => 'status', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false'],
            ],
        ]));

        $response->assertOk();
        $actions = (string) ($response->json('data.0.actions') ?? '');
        $this->assertStringNotContainsString('Actions', $actions);
        $this->assertStringContainsString('—', $actions);
    }

    public function test_notifications_view_only_user_does_not_see_manage_controls(): void
    {
        $viewer = $this->userWithPermissions(['notifications.view']);
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        Notification::query()->create([
            'user_id' => $manager->id,
            'channel' => 'email',
            'type' => 'critical_alert',
            'subject' => 'Unread alert for viewer test',
            'body' => 'Body text',
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('Unread alert for viewer test')
            ->assertDontSee('Mark all read', false)
            ->assertDontSee('Delete Notification', false);
    }

    public function test_users_view_only_user_can_list_users_without_manage_controls(): void
    {
        $viewer = $this->userWithPermissions(['users.view']);
        User::factory()->create(['name' => 'Listed User']);

        $this->actingAs($viewer)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Listed User')
            ->assertDontSee('+ New User', false);

        $response = $this->actingAs($viewer)->getJson(route('admin.users.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'name_formatted', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'email', 'name' => 'email', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'role_formatted', 'name' => 'role', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'created_by_formatted', 'name' => 'created_by', 'searchable' => 'false', 'orderable' => 'false'],
                ['data' => 'created_at_formatted', 'name' => 'created_at', 'searchable' => 'false', 'orderable' => 'true'],
                ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false'],
            ],
        ]));

        $response->assertOk();
        $actions = (string) ($response->json('data.0.actions') ?? '');
        $this->assertStringNotContainsString('Edit', $actions);
        $this->assertStringNotContainsString('Delete', $actions);
        $this->assertStringContainsString('View', $actions);
    }

    public function test_roles_view_only_user_can_list_roles_without_manage_controls(): void
    {
        $viewer = $this->userWithPermissions(['roles.view']);

        $this->actingAs($viewer)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('Project Manager')
            ->assertDontSee('+ Create Role', false);

        $response = $this->actingAs($viewer)->getJson(route('admin.roles.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
        ]));

        $response->assertOk();
        $actions = (string) ($response->json('data.0.actions') ?? '');
        $this->assertStringNotContainsString('Edit Role', $actions);
        $this->assertStringNotContainsString('Delete Role', $actions);
        $this->assertStringContainsString('View', $actions);
    }
}
