<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckWorkerHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'system@codereviewapp.online']);
        Mail::fake();
    }

    public function test_detects_worker_offline_and_sends_single_alert_per_episode(): void
    {
        config(['services.alerts.critical_emails' => 'alerts@example.com']);

        $worker = WorkerHeartbeat::query()->create([
            'worker_id' => 'mac-mini-primary',
            'hostname' => 'mac.local',
            'last_ping_at' => Carbon::now()->subMinutes(20),
            'is_currently_offline' => false,
        ]);

        // 1st Check: Detects offline, sends 1 email
        $this->artisan('health:check-workers')->assertExitCode(0);

        $worker->refresh();
        $this->assertTrue($worker->is_currently_offline);
        $this->assertNotNull($worker->current_outage_episode_id);
        $this->assertNotNull($worker->last_outage_alert_at);

        $this->assertDatabaseHas('notifications', [
            'type' => 'worker_offline_alert',
            'status' => NotificationStatus::Sent->value,
        ]);

        // 2nd Check during same outage episode: Suppresses duplicate alert
        $this->artisan('health:check-workers')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_empty_critical_alert_emails_suppresses_alert_without_error(): void
    {
        config(['services.alerts.critical_emails' => '']);

        WorkerHeartbeat::query()->create([
            'worker_id' => 'mac-mini-primary',
            'last_ping_at' => Carbon::now()->subMinutes(20),
            'is_currently_offline' => false,
        ]);

        $this->artisan('health:check-workers')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_no_expected_workers_configured_creates_no_records_or_alerts(): void
    {
        config(['worker.expected_ids' => [], 'services.alerts.critical_emails' => 'alerts@example.com']);

        $this->artisan('health:check-workers')->assertExitCode(0);

        $this->assertDatabaseCount('worker_heartbeats', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_expected_worker_that_never_connected_is_tracked_and_alerted_once_per_day(): void
    {
        config(['worker.expected_ids' => ['mac-mini-primary'], 'services.alerts.critical_emails' => 'alerts@example.com']);

        $this->artisan('health:check-workers')->assertExitCode(0);

        $worker = WorkerHeartbeat::query()->where('worker_id', 'mac-mini-primary')->firstOrFail();
        $this->assertNull($worker->last_ping_at);
        $this->assertTrue($worker->is_currently_offline);
        $this->assertStringContainsString('never_connected', (string) $worker->current_outage_episode_id);
        $this->assertDatabaseCount('notifications', 1);

        // Same day: alert suppressed for the same episode
        $this->artisan('health:check-workers')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 1);

        // Next day: a new episode allows one reminder
        $this->travel(1)->days();
        $this->artisan('health:check-workers')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_worker_recovery_resets_episode_state(): void
    {
        $worker = WorkerHeartbeat::query()->create([
            'worker_id' => 'mac-mini-primary',
            'last_ping_at' => Carbon::now(),
            'is_currently_offline' => true,
            'current_outage_episode_id' => 'outage_old_123',
        ]);

        $this->artisan('health:check-workers')->assertExitCode(0);

        $worker->refresh();
        $this->assertFalse($worker->is_currently_offline);
        $this->assertNull($worker->current_outage_episode_id);
    }
}
