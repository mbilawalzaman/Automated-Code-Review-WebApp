<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileResponsiveLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');
    }

    public function test_admin_dashboard_renders_mobile_drawer_and_hamburger_button(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard'));

        $response->assertStatus(200);
        // Verify Alpine mobile drawer state
        $response->assertSee('x-data="{ sidebarOpen: false }"', false);
        $response->assertSee('toggle-sidebar');

        // Verify responsive transform classes on sidebar drawer
        $response->assertSee('-translate-x-full', false);
        $response->assertSee('md:translate-x-0', false);

        // Verify hamburger button attributes
        $response->assertSee('aria-label="Toggle navigation menu"', false);
    }

    public function test_reports_page_renders_responsive_table_wrapper(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.reports.index'));

        $response->assertStatus(200);
        $response->assertSee('overflow-x-auto min-w-full', false);
    }

    public function test_audit_logs_page_renders_responsive_table_wrapper(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.audit-logs.index'));

        $response->assertStatus(200);
        $response->assertSee('overflow-x-auto min-w-full', false);
    }
}
