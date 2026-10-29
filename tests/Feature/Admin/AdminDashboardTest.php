<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{

    public function test_guests_are_redirected_from_admin_dashboard(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_verified_users_can_access_admin_dashboard(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertViewIs('admin.dashboard');
    }

    public function test_verified_users_can_access_admin_module_pages(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email_verified_at' => now(),
        ]);

        $routes = [
            'admin.projects.index',
            'admin.repositories.index',
            'admin.reviews.index',
            'admin.findings.index',
            'admin.reports.index',
            'admin.audit-logs.index',
            'admin.settings.index',
        ];

        foreach ($routes as $routeName) {
            $response = $this->actingAs($user)->get(route($routeName));
            $response->assertOk();
        }
    }
}
