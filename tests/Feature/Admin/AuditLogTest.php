<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Events\Rbac\RoleUpdatedEvent;
use App\Events\User\UserCreatedEvent;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AuditLogService;
use BadMethodCallException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->superAdmin = User::factory()->superAdmin()->create([
            'email' => 'admin_audit@qaplatform.test',
        ]);

        $this->developer = User::factory()->developer()->create([
            'email' => 'dev_audit@qaplatform.test',
        ]);
    }

    public function test_audit_logs_page_requires_audit_view_permission(): void
    {
        $response = $this->actingAs($this->developer)->get(route('admin.audit-logs.index'));
        $response->assertStatus(403);

        $response = $this->actingAs($this->superAdmin)->get(route('admin.audit-logs.index'));
        $response->assertStatus(200);
    }

    public function test_audit_log_entries_are_immutable(): void
    {
        $auditLog = AuditLog::query()->create([
            'user_id' => $this->superAdmin->id,
            'category' => AuditCategory::Auth,
            'event_type' => AuditEventType::Login,
            'severity' => AuditSeverity::Info,
            'action' => 'User logged in',
        ]);

        $this->expectException(BadMethodCallException::class);
        $auditLog->update(['action' => 'Modified action']);
    }

    public function test_audit_log_deletion_is_blocked(): void
    {
        $auditLog = AuditLog::query()->create([
            'user_id' => $this->superAdmin->id,
            'category' => AuditCategory::Auth,
            'event_type' => AuditEventType::Login,
            'severity' => AuditSeverity::Info,
            'action' => 'User logged in',
        ]);

        $this->expectException(BadMethodCallException::class);
        $auditLog->delete();
    }

    public function test_sensitive_attributes_are_automatically_redacted(): void
    {
        $service = app(AuditLogService::class);

        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret_password_123',
            'token' => 'bearer_token_xyz',
            'api_key' => 'sk_live_123456789',
        ];

        $sanitized = $service->redactSensitiveData($data);

        $this->assertEquals('John Doe', $sanitized['name']);
        $this->assertEquals('john@example.com', $sanitized['email']);
        $this->assertEquals('[REDACTED]', $sanitized['password']);
        $this->assertEquals('[REDACTED]', $sanitized['token']);
        $this->assertEquals('[REDACTED]', $sanitized['api_key']);
    }

    public function test_domain_events_trigger_audit_log_recording(): void
    {
        $targetUser = User::factory()->developer()->create();

        UserCreatedEvent::dispatch($targetUser, [
            'email' => $targetUser->email,
            'password' => 'secret_pass',
        ], $this->superAdmin);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->superAdmin->id,
            'category' => AuditCategory::User->value,
            'event_type' => AuditEventType::Created->value,
        ]);

        $log = AuditLog::query()->where('user_id', $this->superAdmin->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('[REDACTED]', $log->after_state['password']);
    }

    public function test_datatables_endpoint_returns_json(): void
    {
        AuditLog::query()->create([
            'user_id' => $this->superAdmin->id,
            'category' => AuditCategory::Rbac,
            'event_type' => AuditEventType::Updated,
            'severity' => AuditSeverity::Warning,
            'action' => 'Updated role permissions',
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson(route('admin.audit-logs.data'));

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_audit_log_details_endpoint_returns_json_payload(): void
    {
        $log = AuditLog::query()->create([
            'user_id' => $this->superAdmin->id,
            'category' => AuditCategory::Rbac,
            'event_type' => AuditEventType::Updated,
            'severity' => AuditSeverity::Warning,
            'action' => 'Updated role permissions',
            'before_state' => ['role' => 'developer'],
            'after_state' => ['role' => 'project_manager'],
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson(route('admin.audit-logs.show', $log));

        $response->assertStatus(200)
            ->assertJson([
                'id' => $log->id,
                'action' => 'Updated role permissions',
                'actor' => $this->superAdmin->name,
                'before_state' => ['role' => 'developer'],
                'after_state' => ['role' => 'project_manager'],
            ]);
    }

    public function test_audit_log_export_csv_and_json(): void
    {
        AuditLog::query()->create([
            'user_id' => $this->superAdmin->id,
            'category' => AuditCategory::Auth,
            'event_type' => AuditEventType::Login,
            'severity' => AuditSeverity::Info,
            'action' => 'User logged in',
        ]);

        $csvResponse = $this->actingAs($this->superAdmin)->get(route('admin.audit-logs.export', ['format' => 'csv']));
        $csvResponse->assertStatus(200);
        $csvResponse->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $jsonResponse = $this->actingAs($this->superAdmin)->get(route('admin.audit-logs.export', ['format' => 'json']));
        $jsonResponse->assertStatus(200);
        $jsonResponse->assertHeader('content-type', 'application/json');
    }

    public function test_framework_login_event_creates_audit_log(): void
    {
        event(new \Illuminate\Auth\Events\Login('web', $this->developer, false));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->developer->id,
            'category' => AuditCategory::Auth->value,
            'event_type' => AuditEventType::Login->value,
        ]);
    }

    public function test_framework_login_failed_event_creates_audit_log(): void
    {
        event(new \Illuminate\Auth\Events\Failed('web', null, ['email' => 'unknown@qaplatform.test']));

        $this->assertDatabaseHas('audit_logs', [
            'category' => AuditCategory::Auth->value,
            'event_type' => AuditEventType::LoginFailed->value,
            'severity' => AuditSeverity::Warning->value,
        ]);
    }

    public function test_framework_logout_event_creates_audit_log(): void
    {
        event(new \Illuminate\Auth\Events\Logout('web', $this->developer));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->developer->id,
            'category' => AuditCategory::Auth->value,
            'event_type' => AuditEventType::Logout->value,
        ]);
    }
}
