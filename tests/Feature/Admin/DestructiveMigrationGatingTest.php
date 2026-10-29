<?php

namespace Tests\Feature\Admin;

use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DestructiveMigrationGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_destructive_migration_override_blocked_without_verified_backup(): void
    {
        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('listBackups')->once()->andReturn([]);
        });

        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
            'change_classes' => ['destructive_migration'],
            'overridden_at' => null,
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->from(route('admin.reviews.index'))->post(
            route('admin.reviews.override', $review),
            ['reason' => 'Need to ship drop column migration urgently'],
        );

        $response->assertRedirect(route('admin.reviews.index'));
        $response->assertSessionHas('error');
        $this->assertNull($review->fresh()->overridden_at);
        $this->assertStringContainsString('verified backup', (string) session('error'));
    }

    public function test_destructive_migration_override_allowed_with_recent_verified_backup(): void
    {
        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('listBackups')->once()->andReturn([
                [
                    'filename' => '2026-07-31_12-00-00.enc',
                    'path' => storage_path('app/backups/2026-07-31_12-00-00.enc'),
                    'created_at' => now()->subHour()->format('Y-m-d H:i:s'),
                    'size_bytes' => 100,
                ],
            ]);
            $mock->shouldReceive('verifyBackup')
                ->once()
                ->andReturn([
                    'valid' => true,
                    'filename' => '2026-07-31_12-00-00.enc',
                    'expected_hash' => 'abc',
                    'computed_hash' => 'abc',
                    'message' => 'ok',
                    'manifest' => [],
                ]);
        });

        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
            'change_classes' => ['destructive_migration'],
            'overridden_at' => null,
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->post(
            route('admin.reviews.override', $review),
            ['reason' => 'Backup verified; stakeholders approved drop'],
        );

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertNotNull($review->fresh()->overridden_at);
    }

    public function test_api_contract_override_requires_documentation(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
            'change_classes' => ['api_contract'],
            'overridden_at' => null,
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->from(route('admin.reviews.index'))->post(
            route('admin.reviews.override', $review),
            ['reason' => 'Breaking route removal approved by PM'],
        );

        $response->assertRedirect(route('admin.reviews.index'));
        $response->assertSessionHasErrors('documentation_url');
        $this->assertNull($review->fresh()->overridden_at);
    }

    public function test_api_contract_override_succeeds_with_documentation_notes(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
            'change_classes' => ['api_contract'],
            'overridden_at' => null,
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->post(
            route('admin.reviews.override', $review),
            [
                'reason' => 'Breaking route removal approved by PM',
                'documentation_notes' => 'Consumers notified via changelog on 2026-07-31.',
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertNotNull($review->fresh()->overridden_at);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
