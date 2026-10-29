<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class HealthApiTest extends TestCase
{
    public function test_health_endpoint_returns_ok_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJson([
                'service' => config('app.name'),
                'status' => 'ok',
                'version' => 'v1',
            ]);
    }

    public function test_web_health_endpoint_returns_ok_status(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJson([
                'service' => config('app.name'),
                'status' => 'ok',
                'version' => 'v1',
            ]);
    }
}
