<?php

namespace Tests\Feature\Admin;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewJob;
use App\Models\User;
use App\Services\Api\FindingReconciliationService;
use App\Services\Api\RedactionService;
use App\Services\Api\ReviewJobFinalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase20SecurityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->pm = User::factory()->projectManager()->create(['email_verified_at' => now()]);
    }

    public function test_developer_cannot_access_admin_dashboard(): void
    {
        $developer = User::factory()->developer()->create(['email_verified_at' => now()]);

        $this->actingAs($developer)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($developer)->get(route('admin.reviews.index'))->assertForbidden();
    }

    public function test_project_manager_can_access_dashboard(): void
    {
        $this->actingAs($this->pm)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_redaction_service_strips_secret_matches(): void
    {
        $service = app(RedactionService::class);

        $this->assertStringContainsString(
            '[REDACTED]',
            $service->redact('Potential secret. Match: sk-live-ABCDEFG1234567890')
        );
        $this->assertStringNotContainsString(
            'sk-live',
            $service->redact('api_key=super-secret-value-here')
        );
    }

    public function test_findings_are_redacted_on_finalize_and_reconciled_across_reviews(): void
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'status' => RepositoryStatus::Active,
        ]);

        $firstJob = ReviewJob::query()->create([
            'repository_id' => $repository->id,
            'status' => ReviewJobStatus::Processing,
            'trigger_type' => 'webhook_push',
            'idempotency_key' => 'phase20-first-'.uniqid(),
            'started_at' => now()->subMinutes(5),
            'commit_sha' => str_repeat('a', 40),
        ]);

        $fingerprint = app(RedactionService::class)->fingerprint(
            'generic-api-key',
            'config/.env',
            12,
            'Hardcoded Secret'
        );

        $finalizer = app(ReviewJobFinalizationService::class);
        $first = $finalizer->finalizeJob($firstJob->id, [
            'status' => 'failed',
            'summary' => 'Found a secret',
            'findings' => [[
                'title' => 'Hardcoded Secret',
                'severity' => 'high',
                'file_path' => 'config/.env',
                'line_number' => 12,
                'rule_id' => 'generic-api-key',
                'evidence' => 'Match: sk-live-ABCDEFG1234567890',
                'recommendation' => 'Remove the token=abc123 value',
                'fingerprint' => $fingerprint,
                'is_blocking' => true,
            ]],
            'tools_used' => ['gitleaks'],
        ]);

        $stored = Finding::query()->where('review_id', $first['review']->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame($fingerprint, $stored->fingerprint);
        $this->assertStringContainsString('[REDACTED]', $stored->evidence);
        $this->assertStringNotContainsString('sk-live', $stored->evidence);
        $this->assertSame(FindingStatus::Open, $stored->status);

        $secondJob = ReviewJob::query()->create([
            'repository_id' => $repository->id,
            'status' => ReviewJobStatus::Processing,
            'trigger_type' => 'webhook_push',
            'idempotency_key' => 'phase20-second-'.uniqid(),
            'started_at' => now()->subMinutes(2),
            'commit_sha' => str_repeat('b', 40),
        ]);

        $second = $finalizer->finalizeJob($secondJob->id, [
            'status' => 'passed',
            'summary' => 'Clean review',
            'findings' => [],
            'tools_used' => ['gitleaks'],
        ]);

        $stored->refresh();
        $this->assertSame(FindingStatus::Resolved, $stored->status);
        $this->assertSame($second['review']->id, $stored->resolved_in_review_id);
        $this->assertNotNull($stored->resolved_at);
        $this->assertFalse($stored->is_blocking);
    }

    public function test_review_and_finding_detail_endpoints_and_filters(): void
    {
        $repository = Repository::factory()->create(['status' => RepositoryStatus::Active]);
        $review = Review::factory()->create([
            'repository_id' => $repository->id,
            'status' => ReviewStatus::Failed,
            'author' => 'alice',
            'summary' => 'Match: ghp_abcdefghijklmnopqrstuvwxyz',
            'completed_at' => now(),
        ]);
        $finding = Finding::factory()->create([
            'review_id' => $review->id,
            'severity' => FindingSeverity::High,
            'status' => FindingStatus::Open,
            'evidence' => 'Match: ghp_abcdefghijklmnopqrstuvwxyz',
            'title' => 'Secret leaked',
        ]);

        $this->actingAs($this->pm)
            ->getJson(route('admin.reviews.show', $review))
            ->assertOk()
            ->assertJsonPath('id', $review->id)
            ->assertJsonMissing(['ghp_abcdefghijklmnopqrstuvwxyz']);

        $this->actingAs($this->pm)
            ->getJson(route('admin.findings.show', $finding))
            ->assertOk()
            ->assertJsonPath('id', $finding->id)
            ->assertJsonMissing(['ghp_abcdefghijklmnopqrstuvwxyz']);

        $this->actingAs($this->pm)
            ->getJson(route('admin.reviews.data', [
                'repository_id' => $repository->id,
                'status' => ReviewStatus::Failed->value,
            ]))
            ->assertOk();

        $this->actingAs($this->pm)
            ->getJson(route('admin.findings.data', [
                'severity' => FindingSeverity::High->value,
                'status' => FindingStatus::Open->value,
            ]))
            ->assertOk();
    }

    public function test_review_now_accepts_branch_parameter(): void
    {
        $repository = Repository::factory()->create([
            'status' => RepositoryStatus::Active,
            'default_branch' => 'main',
        ]);

        $response = $this->actingAs($this->pm)->post(
            route('admin.repositories.review-now', $repository),
            ['branch' => 'feature/phase-20']
        );

        $response->assertRedirect();
        $job = ReviewJob::query()->where('repository_id', $repository->id)->latest('id')->first();
        $this->assertNotNull($job);
        $this->assertSame('manual_review_now', $job->trigger_type);

        $payload = is_array($job->payload) ? $job->payload : json_decode((string) $job->payload, true);
        $this->assertSame('feature/phase-20', $payload['branch_name'] ?? null);
    }

    public function test_fingerprint_helper_is_stable(): void
    {
        $service = app(RedactionService::class);
        $a = $service->fingerprint('r1', 'App/Foo.php', 3, 'Title One');
        $b = $service->fingerprint('r1', 'app/foo.php', 5, 'Title One');
        $this->assertSame($a, $b);
    }

    public function test_reconciliation_service_links_parent_finding(): void
    {
        $repository = Repository::factory()->create(['status' => RepositoryStatus::Active]);
        $priorReview = Review::factory()->create(['repository_id' => $repository->id]);
        $fingerprint = app(RedactionService::class)->fingerprint('rule', 'a.php', 1, 'Same');

        $parent = Finding::factory()->create([
            'review_id' => $priorReview->id,
            'fingerprint' => $fingerprint,
            'status' => FindingStatus::Open,
            'title' => 'Same',
            'file_path' => 'a.php',
            'line_number' => 1,
            'rule_id' => 'rule',
        ]);

        $newReview = Review::factory()->create(['repository_id' => $repository->id]);
        $result = app(FindingReconciliationService::class)->reconcile($newReview, [[
            'title' => 'Same',
            'file_path' => 'a.php',
            'line_number' => 2,
            'rule_id' => 'rule',
            'fingerprint' => $fingerprint,
        ]]);

        $this->assertSame($parent->id, $result['findings'][0]['parent_finding_id']);
    }
}
