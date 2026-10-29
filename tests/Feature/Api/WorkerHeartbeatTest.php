<?php

namespace Tests\Feature\Api;

use App\Models\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected string $workerToken = 'test_worker_bearer_token_999';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.worker.token' => $this->workerToken]);
    }

    public function test_heartbeat_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/worker/heartbeat', [
            'worker_id' => 'mac-mini-primary',
        ]);

        $response->assertStatus(401);
    }

    public function test_heartbeat_upserts_worker_record_without_duplicates(): void
    {
        $payload = [
            'worker_id' => 'mac-mini-primary',
            'hostname' => 'mac-mini.local',
            'os_info' => 'Darwin 23.4.0',
            'python_version' => '3.11.5',
            'payload' => ['tools' => ['semgrep' => '1.45.0']],
        ];

        // 1st Ping
        $res1 = $this->withToken($this->workerToken)
            ->postJson('/api/v1/worker/heartbeat', $payload);

        $res1->assertStatus(200);
        $res1->assertJson(['success' => true, 'worker_id' => 'mac-mini-primary']);

        $this->assertDatabaseCount('worker_heartbeats', 1);

        // 2nd Ping from same worker_id
        $res2 = $this->withToken($this->workerToken)
            ->postJson('/api/v1/worker/heartbeat', $payload);

        $res2->assertStatus(200);
        $this->assertDatabaseCount('worker_heartbeats', 1);
    }

    public function test_validates_and_sanitizes_input_strings(): void
    {
        $response = $this->withToken($this->workerToken)
            ->postJson('/api/v1/worker/heartbeat', [
                'worker_id' => '  mac-mini-primary  ',
                'hostname' => '  macbook.local  ',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('worker_heartbeats', [
            'worker_id' => 'mac-mini-primary',
            'hostname' => 'macbook.local',
        ]);
    }
}
