<?php

namespace Tests\Feature\Security;

use App\Enums\FindingStatus;
use App\Enums\RepositoryStatus;
use App\Enums\ReviewStatus;
use App\Models\Finding;
use App\Models\Repository;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;

class SensitiveActionReauthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_review_override_requires_recent_password_confirmation(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create(['status' => ReviewStatus::Failed, 'overridden_at' => null]);

        $this->actingAs($manager)
            ->post(route('admin.reviews.override', $review), ['reason' => 'Approved by stakeholders for hotfix'])
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($review->fresh()->overridden_at);
    }

    public function test_finding_disposition_requires_recent_password_confirmation(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $finding = Finding::factory()->create(['status' => FindingStatus::Open]);

        $this->actingAs($manager)
            ->post(route('admin.findings.accept', $finding), ['reason' => 'Accepted risk for this release'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
    }

    public function test_repository_activation_requires_recent_password_confirmation(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create(['status' => RepositoryStatus::Connected->value]);

        $this->actingAs($manager)
            ->patch(route('admin.repositories.activate', $repository))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(RepositoryStatus::Connected, $repository->fresh()->status);
    }

    public function test_stale_confirmation_no_longer_satisfies_the_gate(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create(['status' => ReviewStatus::Failed, 'overridden_at' => null]);

        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => time() - (config('auth.password_timeout') + 60)])
            ->post(route('admin.reviews.override', $review), ['reason' => 'Stale confirmation should be rejected'])
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($review->fresh()->overridden_at);
    }

    public function test_confirmation_page_returns_the_user_to_the_originating_screen(): void
    {
        $manager = User::factory()->projectManager()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('correct-horse-battery'),
        ]);

        $this->actingAs($manager)
            ->get(route('password.confirm', ['redirect' => '/admin/reviews']))
            ->assertOk();

        $this->actingAs($manager)
            ->post(route('password.confirm'), ['password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/reviews');
    }

    public function test_absolute_redirect_targets_are_not_honoured(): void
    {
        $manager = User::factory()->projectManager()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('correct-horse-battery'),
        ]);

        $this->actingAs($manager)
            ->get(route('password.confirm', ['redirect' => 'https://evil.example.com/steal']))
            ->assertOk();

        $response = $this->actingAs($manager)
            ->post(route('password.confirm'), ['password' => 'correct-horse-battery']);

        $this->assertNotSame('https://evil.example.com/steal', $response->headers->get('Location'));
    }
}
