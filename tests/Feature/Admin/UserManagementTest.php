<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_can_view_user_management_index(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('admin.users.index'));

        $response->assertOk()
            ->assertViewIs('admin.users.index')
            ->assertSee($superAdmin->name);
    }

    public function test_super_admin_can_create_new_user(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('admin.users.store'), [
            'name' => 'New Auditor User',
            'email' => 'new.auditor@qaplatform.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::SecurityAuditor->value,
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'name' => 'New Auditor User',
            'email' => 'new.auditor@qaplatform.test',
            'role' => UserRole::SecurityAuditor->value,
        ]);
    }

    public function test_super_admin_can_update_user_details_and_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $targetUser = User::factory()->developer()->create();

        $response = $this->actingAs($superAdmin)->put(route('admin.users.update', $targetUser), [
            'name' => 'Promoted Developer',
            'email' => $targetUser->email,
            'role' => UserRole::ProjectManager->value,
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'name' => 'Promoted Developer',
            'role' => UserRole::ProjectManager->value,
        ]);
    }

    public function test_super_admin_can_delete_another_user(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $targetUser = User::factory()->developer()->create();

        $response = $this->actingAs($superAdmin)->delete(route('admin.users.destroy', $targetUser));

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('users', [
            'id' => $targetUser->id,
        ]);
    }

    public function test_super_admin_cannot_delete_their_own_account(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->delete(route('admin.users.destroy', $superAdmin));

        $response->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error', 'You cannot delete your own account.');

        $this->assertDatabaseHas('users', [
            'id' => $superAdmin->id,
        ]);
    }

    public function test_users_view_permission_allows_read_only_access(): void
    {
        $manager = User::factory()->projectManager()->create();

        $this->actingAs($manager)->get(route('admin.users.index'))
            ->assertOk()
            ->assertViewIs('admin.users.index')
            ->assertDontSee('+ New User', false);

        $this->actingAs($manager)->get(route('admin.users.data'))
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_users_view_without_manage_cannot_mutate_users(): void
    {
        $manager = User::factory()->projectManager()->create();
        $targetUser = User::factory()->developer()->create();

        $this->actingAs($manager)->get(route('admin.users.create'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');

        $this->actingAs($manager)->post(route('admin.users.store'), [
            'name' => 'Blocked User',
            'email' => 'blocked@qaplatform.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::Developer->value,
        ])->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');

        $this->actingAs($manager)->put(route('admin.users.update', $targetUser), [
            'name' => 'Blocked Update',
            'email' => $targetUser->email,
            'role' => UserRole::ProjectManager->value,
        ])->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }

    public function test_roles_without_users_view_are_denied_user_list_access(): void
    {
        $auditor = User::factory()->securityAuditor()->create();

        $this->actingAs($auditor)->get(route('admin.users.index'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }

    public function test_developer_role_is_denied_admin_user_routes(): void
    {
        $developer = User::factory()->developer()->create();

        $this->actingAs($developer)->get(route('admin.users.index'))->assertForbidden();
    }
}
