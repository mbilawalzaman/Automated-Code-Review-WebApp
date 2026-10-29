<?php

namespace Tests\Feature\Admin;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ReviewStatus;
use App\Models\Finding;
use App\Models\Review;
use App\Models\User;
use App\Services\Admin\TrendAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;

class TrendAnalyticsTest extends TestCase
{
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = User::factory()->superAdmin()->create(['email_verified_at' => now()]);
    }

    public function test_dashboard_includes_trend_series(): void
    {
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
            'completed_at' => now(),
            'changed_code_coverage' => 62.5,
        ]);

        Finding::factory()->create([
            'review_id' => $review->id,
            'severity' => FindingSeverity::Critical,
            'status' => FindingStatus::Open,
            'created_at' => now(),
        ]);

        Finding::factory()->create([
            'review_id' => $review->id,
            'severity' => FindingSeverity::High,
            'status' => FindingStatus::Resolved,
            'resolved_at' => now(),
            'created_at' => now()->subDay(),
        ]);

        $trends = app(TrendAnalyticsService::class)->dashboardTrends(7);

        $this->assertCount(7, $trends['labels']);
        $this->assertArrayHasKey('critical', $trends['security_findings']);
        $this->assertNotEmpty($trends['coverage']);
        $this->assertArrayHasKey('rate', $trends['failure_rates']);
        $this->assertArrayHasKey('opened', $trends['resolution']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Security findings by severity')
            ->assertSee('chart-security-findings', false);
    }
}
