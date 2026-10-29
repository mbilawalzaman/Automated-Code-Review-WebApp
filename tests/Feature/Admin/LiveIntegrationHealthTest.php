<?php

namespace Tests\Feature\Admin;

use App\Enums\IntegrationType;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\IntegrationCredentialService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveIntegrationHealthTest extends TestCase
{
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = User::factory()->superAdmin()->create(['email_verified_at' => now()]);
    }

    public function test_health_page_shows_live_probe_labels(): void
    {
        Http::fake([
            'api.trello.com/*' => Http::response(['id' => '1', 'username' => 'bot'], 200),
            'api.github.com/*' => Http::response(['total_count' => 0, 'repositories' => []], 200),
            'www.googleapis.com/*' => Http::response(['id' => 'folder'], 200),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        ]);

        config([
            'services.trello.key' => 'trello-key',
            'services.trello.token' => 'trello-token',
            'github.token' => 'ghp_test',
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.health.index'))
            ->assertOk()
            ->assertSee('External Integrations (live probes)')
            ->assertSee('Trello Automation Account');
    }

    public function test_credential_rotation_is_read_by_credential_service(): void
    {
        $this->actingAs($this->superAdmin)
            ->withConfirmedPassword()
            ->post(route('admin.integrations.update'), [
                'type' => 'trello',
                'key' => 'rotated-key',
                'token' => 'rotated-token',
            ])
            ->assertRedirect(route('admin.integrations.index'));

        $resolved = app(IntegrationCredentialService::class)->trello();
        $this->assertSame('rotated-key', $resolved['key']);
        $this->assertSame('rotated-token', $resolved['token']);
        $this->assertSame('database', $resolved['source']);

        $this->assertDatabaseHas('integrations', [
            'type' => IntegrationType::Trello->value,
            'is_active' => 1,
        ]);

        $integration = Integration::query()->where('type', IntegrationType::Trello->value)->first();
        $this->assertSame('rotated-key', $integration->credentials['key']);
    }

    public function test_clearing_stored_credentials_falls_back_to_env(): void
    {
        $this->actingAs($this->superAdmin)
            ->withConfirmedPassword()
            ->post(route('admin.integrations.update'), [
                'type' => 'trello',
                'key' => 'rotated-key',
                'token' => 'rotated-token',
            ]);

        config([
            'services.trello.key' => 'env-key',
            'services.trello.token' => 'env-token',
        ]);

        $this->actingAs($this->superAdmin)
            ->withConfirmedPassword()
            ->post(route('admin.integrations.clear'), ['type' => 'trello'])
            ->assertRedirect(route('admin.integrations.index'))
            ->assertSessionHas('status', 'Trello stored credentials cleared. Live services now read from environment configuration.');

        $resolved = app(IntegrationCredentialService::class)->trello();
        $this->assertSame('env-key', $resolved['key']);
        $this->assertSame('env-token', $resolved['token']);
        $this->assertSame('env', $resolved['source']);

        $integration = Integration::query()->where('type', IntegrationType::Trello->value)->first();
        $this->assertFalse($integration->is_active);
        $this->assertSame([], $integration->credentials);
    }

    public function test_clear_requires_password_confirmation(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.integrations.clear'), ['type' => 'trello'])
            ->assertRedirect(route('password.confirm'));
    }
}
