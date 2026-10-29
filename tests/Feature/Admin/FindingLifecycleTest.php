<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\FindingStatus;
use App\Enums\ReviewStatus;
use App\Models\AuditLog;
use App\Models\Finding;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_project_manager_can_mark_finding_as_false_positive_with_audit(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $finding = Finding::factory()->create([
            'status' => FindingStatus::Open,
            'is_blocking' => true,
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->post(
            route('admin.findings.false-positive', $finding),
            ['reason' => 'Scanner false alarm on generated fixture file'],
        );

        $response->assertRedirect();
        $finding->refresh();

        $this->assertSame(FindingStatus::FalsePositive, $finding->status);
        $this->assertFalse($finding->is_blocking);
        $this->assertSame('Scanner false alarm on generated fixture file', $finding->false_positive_reason);

        $this->assertDatabaseHas('audit_logs', [
            'category' => AuditCategory::Finding->value,
            'event_type' => AuditEventType::Updated->value,
            'target_type' => Finding::class,
            'target_id' => $finding->id,
            'user_id' => $manager->id,
            'reason' => 'Scanner false alarm on generated fixture file',
        ]);
    }

    public function test_false_positive_requires_reason(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $finding = Finding::factory()->create(['status' => FindingStatus::Open]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->from(route('admin.findings.index'))->post(
            route('admin.findings.false-positive', $finding),
            ['reason' => 'short'],
        );

        $response->assertRedirect(route('admin.findings.index'));
        $response->assertSessionHasErrors('reason');
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
    }

    public function test_developer_cannot_mark_false_positive(): void
    {
        $developer = User::factory()->developer()->create(['email_verified_at' => now()]);
        $finding = Finding::factory()->create(['status' => FindingStatus::Open]);

        $response = $this->actingAs($developer)->post(
            route('admin.findings.false-positive', $finding),
            ['reason' => 'Developer should not be able to mark this finding'],
        );

        $response->assertForbidden();
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
    }

    public function test_project_manager_can_accept_open_finding(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $finding = Finding::factory()->create([
            'status' => FindingStatus::Open,
            'is_blocking' => true,
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->post(
            route('admin.findings.accept', $finding),
            ['reason' => 'Accepted risk until next sprint remediation'],
        );

        $response->assertRedirect();
        $finding->refresh();

        $this->assertSame(FindingStatus::Accepted, $finding->status);
        $this->assertFalse($finding->is_blocking);
    }

    public function test_project_manager_can_override_failed_review_with_audit(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
            'overridden_at' => null,
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->post(
            route('admin.reviews.override', $review),
            ['reason' => 'Emergency hotfix approved by stakeholders'],
        );

        $response->assertRedirect();
        $review->refresh();

        $this->assertSame(ReviewStatus::Failed, $review->status);
        $this->assertSame($manager->id, $review->overridden_by);
        $this->assertSame('Emergency hotfix approved by stakeholders', $review->override_reason);
        $this->assertNotNull($review->overridden_at);

        $this->assertTrue(
            AuditLog::query()
                ->where('category', AuditCategory::Review->value)
                ->where('event_type', AuditEventType::Override->value)
                ->where('target_type', Review::class)
                ->where('target_id', $review->id)
                ->where('user_id', $manager->id)
                ->where('reason', 'Emergency hotfix approved by stakeholders')
                ->exists()
        );
    }

    public function test_cannot_override_passed_review(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Passed,
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->from(route('admin.reviews.index'))->post(
            route('admin.reviews.override', $review),
            ['reason' => 'Should not override a passed review entry'],
        );

        $response->assertRedirect(route('admin.reviews.index'));
        $response->assertSessionHas('error');
        $this->assertNull($review->fresh()->overridden_at);
    }

    public function test_cannot_override_review_twice(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
            'overridden_by' => $manager->id,
            'override_reason' => 'Already overridden once before',
            'overridden_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($manager)->withConfirmedPassword()->from(route('admin.reviews.index'))->post(
            route('admin.reviews.override', $review),
            ['reason' => 'Second override attempt should be rejected'],
        );

        $response->assertRedirect(route('admin.reviews.index'));
        $response->assertSessionHas('error');
        $this->assertSame('Already overridden once before', $review->fresh()->override_reason);
    }

    public function test_developer_cannot_override_review(): void
    {
        $developer = User::factory()->developer()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
        ]);

        $response = $this->actingAs($developer)->post(
            route('admin.reviews.override', $review),
            ['reason' => 'Developer should not override failed reviews'],
        );

        $response->assertForbidden();
        $this->assertNull($review->fresh()->overridden_at);
    }
}
