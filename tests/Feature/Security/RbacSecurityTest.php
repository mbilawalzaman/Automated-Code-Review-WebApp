<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_developer_cannot_access_system_settings(): void
    {
        $developer = User::factory()->developer()->create();

        $response = $this->actingAs($developer)->get('/admin/settings');

        $response->assertForbidden();
    }

    public function test_developer_cannot_access_audit_logs(): void
    {
        $developer = User::factory()->developer()->create();

        $response = $this->actingAs($developer)->get('/admin/audit-logs');

        $response->assertForbidden();
    }

    public function test_developer_cannot_create_projects(): void
    {
        $developer = User::factory()->developer()->create();

        $response = $this->actingAs($developer)->post('/admin/projects', [
            'name' => 'Unauthorized Project',
            'description' => 'Should be blocked',
        ]);

        $response->assertForbidden();
    }

    public function test_security_auditor_can_access_audit_logs_and_reports(): void
    {
        $auditor = User::factory()->securityAuditor()->create();

        $responseAudit = $this->actingAs($auditor)->get('/admin/audit-logs');
        $responseAudit->assertOk();

        $responseReport = $this->actingAs($auditor)->get('/admin/reports');
        $responseReport->assertOk();
    }

    public function test_security_auditor_cannot_create_projects(): void
    {
        $auditor = User::factory()->securityAuditor()->create();

        $response = $this->actingAs($auditor)->post('/admin/projects', [
            'name' => 'Auditor Project',
            'description' => 'Should be blocked',
        ]);

        $response->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }

    public function test_project_manager_can_create_projects_but_cannot_access_settings(): void
    {
        $manager = User::factory()->projectManager()->create();

        $responseCreate = $this->actingAs($manager)->post('/admin/projects', [
            'name' => 'Manager Project',
            'description' => 'Project created by Manager',
        ]);
        $responseCreate->assertRedirect(route('admin.projects.index'));

        $responseSettings = $this->actingAs($manager)->get('/admin/settings');
        $responseSettings->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }

    public function test_super_admin_bypasses_all_restrictions(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $responseDashboard = $this->actingAs($superAdmin)->get('/admin/dashboard');
        $responseDashboard->assertOk();

        $responseSettings = $this->actingAs($superAdmin)->get('/admin/settings');
        $responseSettings->assertOk();

        $responseAudit = $this->actingAs($superAdmin)->get('/admin/audit-logs');
        $responseAudit->assertOk();

        $responseCreate = $this->actingAs($superAdmin)->post('/admin/projects', [
            'name' => 'SuperAdmin Project',
            'description' => 'Project created by SuperAdmin',
        ]);
        $responseCreate->assertRedirect(route('admin.projects.index'));
    }
}
