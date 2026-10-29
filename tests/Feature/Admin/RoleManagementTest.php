<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_can_access_role_management_page(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('admin.roles.index'));

        $response->assertStatus(200);
        $response->assertSee('Role & Permission Management', false);
    }

    public function test_super_admin_can_fetch_roles_datatables_data(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('admin.roles.data'));

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_super_admin_can_create_custom_role_with_permissions(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $perm1 = Permission::query()->where('name', 'reviews.view')->first();
        $perm2 = Permission::query()->where('name', 'findings.view')->first();

        $response = $this->actingAs($superAdmin)->post(route('admin.roles.store'), [
            'display_name' => 'Custom Security Lead',
            'description' => 'Custom role for security lead auditor',
            'permissions' => [$perm1->id, $perm2->id],
        ]);

        $response->assertRedirect(route('admin.roles.index'));
        $this->assertDatabaseHas('roles', [
            'display_name' => 'Custom Security Lead',
            'is_system' => false,
        ]);

        $role = Role::query()->where('display_name', 'Custom Security Lead')->first();
        $this->assertTrue($role->hasPermissionTo('reviews.view'));
        $this->assertTrue($role->hasPermissionTo('findings.view'));
        $this->assertSame($superAdmin->id, $role->created_by);
    }

    public function test_cannot_create_role_with_duplicate_system_name(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->from(route('admin.roles.index'))->post(route('admin.roles.store'), [
            'display_name' => 'Security Auditor',
            'description' => 'Duplicate of system role',
            '_modal' => 'role-create',
        ]);

        $response->assertRedirect(route('admin.roles.index'));
        $response->assertSessionHasErrors('display_name');
        $this->assertSame(1, Role::query()->where('name', 'security_auditor')->count());
    }

    public function test_super_admin_can_update_role_permissions(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->create([
            'name' => 'test_role',
            'display_name' => 'Test Role',
            'is_system' => false,
        ]);
        $perm = Permission::query()->where('name', 'reports.view')->first();

        $response = $this->actingAs($superAdmin)->put(route('admin.roles.update', $role), [
            'display_name' => 'Updated Test Role',
            'description' => 'Updated description',
            'permissions' => [$perm->id],
        ]);

        $response->assertRedirect(route('admin.roles.index'));
        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'display_name' => 'Updated Test Role',
        ]);
        $this->assertTrue($role->fresh()->hasPermissionTo('reports.view'));
    }

    public function test_super_admin_can_delete_custom_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->create([
            'name' => 'custom_role',
            'display_name' => 'Custom Role',
            'is_system' => false,
        ]);

        $response = $this->actingAs($superAdmin)->delete(route('admin.roles.destroy', $role));

        $response->assertRedirect(route('admin.roles.index'));
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_system_protected_roles_cannot_be_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $systemRole = Role::query()->where('name', 'super_admin')->first();

        $response = $this->actingAs($superAdmin)->delete(route('admin.roles.destroy', $systemRole));

        $response->assertRedirect(route('admin.roles.index'));
        $response->assertSessionHas('error', 'Protected system core roles cannot be deleted.');
        $this->assertDatabaseHas('roles', ['id' => $systemRole->id]);
    }

    public function test_cannot_save_role_with_manage_permission_without_matching_view(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $manageUsers = Permission::query()->where('name', 'users.manage')->firstOrFail();
        $viewUsers = Permission::query()->where('name', 'users.view')->firstOrFail();

        $response = $this->actingAs($superAdmin)->from(route('admin.roles.index'))->post(route('admin.roles.store'), [
            'display_name' => 'Broken Manage Only Role',
            'description' => 'Should fail validation',
            'permissions' => [$manageUsers->id],
            '_modal' => 'role-create',
        ]);

        $response->assertRedirect(route('admin.roles.index'));
        $response->assertSessionHasErrors('permissions');
        $this->assertStringContainsString(
            'View Users',
            session('errors')->get('permissions')[0]
        );
        $this->assertDatabaseMissing('roles', ['display_name' => 'Broken Manage Only Role']);

        $role = Role::query()->create([
            'name' => 'valid_custom_role',
            'display_name' => 'Valid Custom Role',
            'is_system' => false,
        ]);

        $this->actingAs($superAdmin)->from(route('admin.roles.index'))->put(route('admin.roles.update', $role), [
            'display_name' => 'Valid Custom Role',
            'description' => 'Manage with required view',
            'permissions' => [$manageUsers->id, $viewUsers->id],
            '_modal' => 'role-edit-' . $role->id,
        ])->assertRedirect(route('admin.roles.index'));

        $this->assertTrue($role->fresh()->hasPermissionTo('users.manage'));
        $this->assertTrue($role->fresh()->hasPermissionTo('users.view'));
    }

    public function test_roles_view_permission_allows_read_only_access(): void
    {
        $manager = User::factory()->projectManager()->create();
        $viewer = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $role = Role::query()->create([
            'name' => 'viewer_only_'.str()->random(6),
            'display_name' => 'Viewer Only',
            'is_system' => false,
        ]);
        $role->syncPermissions(['roles.view']);
        $viewer->roles()->sync([$role->id]);

        $this->actingAs($viewer)->get(route('admin.roles.index'))
            ->assertOk()
            ->assertDontSee('+ Create Role', false);

        $this->actingAs($viewer)->get(route('admin.roles.data'))
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->actingAs($manager)->get(route('admin.roles.index'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }
}
