<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->superAdmin = User::factory()->superAdmin()->create([
            'email' => 'admin_analytics@qaplatform.test',
        ]);

        $this->developer = User::factory()->developer()->create([
            'email' => 'dev_analytics@qaplatform.test',
        ]);
    }

    public function test_audit_analytics_page_requires_audit_view_permission(): void
    {
        $response = $this->actingAs($this->developer)->get(route('admin.audit-logs.analytics'));
        $response->assertStatus(403);

        $response = $this->actingAs($this->superAdmin)->get(route('admin.audit-logs.analytics'));
        $response->assertStatus(200);
        $response->assertSeeText('Audit Analytics & Operations Dashboard');
    }

    public function test_analytics_data_endpoint_returns_json_structure(): void
    {
        AuditLog::query()->create([
            'user_id' => $this->superAdmin->id,
            'category' => AuditCategory::Auth,
            'event_type' => AuditEventType::Login,
            'severity' => AuditSeverity::Info,
            'action' => 'User logged in',
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson(route('admin.audit-logs.analytics.data'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'kpi' => ['total_events', 'events_24h', 'failed_logins_24h', 'security_alerts_24h', 'review_overrides', 'worker_jobs_24h'],
                'worker_health' => ['is_online', 'status_label', 'status_badge', 'active_workers', 'last_polled_at', 'pending_queue', 'processing_queue', 'avg_duration'],
                'charts' => ['daily_trend', 'categories', 'severities'],
                'recent_security_events',
            ]);
    }

    public function test_worker_health_detects_offline_fallback_state(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson(route('admin.audit-logs.analytics.data'));

        $response->assertStatus(200)
            ->assertJsonPath('worker_health.is_online', false)
            ->assertJsonPath('worker_health.status_label', 'Offline / Waiting for Connection');
    }

    public function test_worker_health_detects_active_worker_polling(): void
    {
        AuditLog::query()->create([
            'user_id' => $this->superAdmin->id,
            'category' => AuditCategory::Worker,
            'event_type' => AuditEventType::JobClaimed,
            'severity' => AuditSeverity::Info,
            'action' => 'Worker engine claimed job',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson(route('admin.audit-logs.analytics.data'));

        $response->assertStatus(200)
            ->assertJsonPath('worker_health.is_online', true)
            ->assertJsonPath('worker_health.status_label', 'Online & Polling');
    }
}
