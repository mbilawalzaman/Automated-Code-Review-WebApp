<?php

namespace Tests\Feature\Admin;

use App\Models\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->developer = User::factory()->developer()->create();
    }

    public function test_super_admin_and_authorized_roles_can_access_health_page(): void
    {
        $response = $this->actingAs($this->superAdmin)->get('/admin/health');
        $response->assertStatus(200);
        $response->assertSee('Real-Time Operational Health');
    }

    public function test_unauthorized_developer_role_cannot_access_health_page(): void
    {
        $response = $this->actingAs($this->developer)->get('/admin/health');
        $response->assertStatus(403);
    }

    public function test_scheduler_freshness_transitions_healthy_to_stale(): void
    {
        // 1. Fresh signal (ISO string — matches production scheduler write)
        Cache::put('scheduler:last_seen_at', now()->toIso8601String(), now()->addMinutes(10));

        $res1 = $this->actingAs($this->superAdmin)->getJson('/admin/health/data');
        $res1->assertStatus(200);
        $res1->assertJsonPath('system.scheduler', 'Healthy & Running');

        // 2. Stale signal (>3 minutes ago)
        Cache::put('scheduler:last_seen_at', now()->subMinutes(5)->toIso8601String(), now()->addMinutes(10));

        $res2 = $this->actingAs($this->superAdmin)->getJson('/admin/health/data');
        $res2->assertStatus(200);
        $res2->assertJsonPath('system.scheduler', 'Stale / Crontab Missing');
    }

    public function test_health_page_survives_legacy_incomplete_class_scheduler_cache(): void
    {
        // Simulate a file-cache unserialize of a Carbon object where the class
        // was not available — the exact staging failure mode.
        $legacy = unserialize('O:12:"MissingClass":0:{}');

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $legacy);

        Cache::put('scheduler:last_seen_at', $legacy, now()->addMinutes(10));

        $this->actingAs($this->superAdmin)
            ->get('/admin/health')
            ->assertOk()
            ->assertSee('Real-Time Operational Health');

        $this->actingAs($this->superAdmin)
            ->getJson('/admin/health/data')
            ->assertOk()
            ->assertJsonPath('system.scheduler', 'No Signal Recorded Yet');
    }
}
