<?php

namespace Tests\Feature\Admin;

use App\Enums\RepositoryStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\Admin\RepositoryActivationPreviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepositoryDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_discover_returns_unavailable_when_github_app_not_configured(): void
    {
        config([
            'github.app_id' => null,
            'github.private_key' => null,
            'github.installation_id' => null,
            'github.token' => null,
        ]);

        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)
            ->getJson(route('admin.repositories.discover'))
            ->assertOk()
            ->assertJson([
                'available' => false,
                'repositories' => [],
            ]);

        $this->assertStringContainsString('GitHub App', (string) $response->json('message'));
    }

    public function test_discover_lists_installation_repositories_and_marks_registered(): void
    {
        config([
            'github.app_id' => '123',
            'github.private_key' => $this->fakePrivateKey(),
            'github.installation_id' => '456',
            'github.token' => null,
        ]);

        Http::fake([
            'https://api.github.com/app/installations/456/access_tokens' => Http::response([
                'token' => 'ghs_fake_installation_token',
            ], 201),
            'https://api.github.com/installation/repositories*' => Http::response([
                'total_count' => 2,
                'repositories' => [
                    [
                        'id' => 101,
                        'name' => 'alpha',
                        'full_name' => 'acme/alpha',
                        'default_branch' => 'main',
                        'private' => true,
                        'html_url' => 'https://github.com/acme/alpha',
                    ],
                    [
                        'id' => 202,
                        'name' => 'beta',
                        'full_name' => 'acme/beta',
                        'default_branch' => 'master',
                        'private' => false,
                        'html_url' => 'https://github.com/acme/beta',
                    ],
                ],
            ], 200),
        ]);

        $project = Project::factory()->create();
        Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'acme/alpha',
            'github_repo_id' => 101,
            'status' => RepositoryStatus::Connected,
        ]);

        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)
            ->getJson(route('admin.repositories.discover'))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(2, 'repositories');

        $repos = collect($response->json('repositories'));
        $this->assertTrue($repos->firstWhere('full_name', 'acme/alpha')['already_registered']);
        $this->assertFalse($repos->firstWhere('full_name', 'acme/beta')['already_registered']);
    }

    public function test_discover_requires_repositories_manage_permission(): void
    {
        $user = User::factory()->developer()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->getJson(route('admin.repositories.discover'))
            ->assertForbidden();
    }

    public function test_activation_preview_includes_webhooks_checks_permissions_and_protection(): void
    {
        config(['github.check_run_name' => 'Automated Code Review']);

        $repository = Repository::factory()->create([
            'full_name' => 'acme/preview-repo',
            'default_branch' => 'main',
            'status' => RepositoryStatus::Connected,
        ]);

        $preview = app(RepositoryActivationPreviewService::class)->preview($repository);

        $this->assertSame('acme/preview-repo', $preview['repository']);
        $this->assertNotEmpty($preview['webhooks']);
        $this->assertSame('push', $preview['webhooks'][0]['event']);
        $this->assertStringContainsString('/api/v1/github/webhook', $preview['webhooks'][0]['url']);
        $this->assertSame('Automated Code Review', $preview['checks'][0]['name']);
        $this->assertTrue(collect($preview['permissions'])->contains(fn ($p) => $p['name'] === 'Checks'));
        $this->assertArrayHasKey('branches', $preview['branch_protection']);
        $this->assertNotEmpty($preview['notes']);
    }

    public function test_repositories_index_exposes_discovery_endpoint(): void
    {
        $user = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('admin.repositories.index'))
            ->assertOk()
            ->assertSee('repositoryDiscovery', false)
            ->assertSee('Discover from GitHub App', false)
            ->assertSee('Load repositories', false);
    }

    private function fakePrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
