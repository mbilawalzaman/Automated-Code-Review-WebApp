<?php

namespace Tests\Feature\Api;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewJob;
use App\Repositories\Eloquent\FindingRepository;
use App\Services\Api\RedactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingBulkInsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_many_accepts_mixed_parent_finding_id_presence(): void
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->create(['project_id' => $project->id]);
        $priorReview = Review::factory()->create(['repository_id' => $repository->id]);
        $review = Review::factory()->create(['repository_id' => $repository->id]);

        $parent = Finding::factory()->create([
            'review_id' => $priorReview->id,
            'fingerprint' => 'fp-shared',
            'status' => FindingStatus::Open,
        ]);

        $rows = [
            [
                'review_id' => $review->id,
                'file_path' => 'app/WithParent.php',
                'line_number' => 10,
                'severity' => FindingSeverity::High->value,
                'status' => FindingStatus::Open->value,
                'title' => 'Recurring finding',
                'evidence' => 'Evidence A',
                'recommendation' => 'Fix A',
                'rule_id' => 'RULE-A',
                'fingerprint' => 'fp-shared',
                'source' => 'gitleaks',
                'is_blocking' => true,
                'objective_id' => null,
                'objective_impact' => null,
                'parent_finding_id' => $parent->id,
            ],
            [
                'review_id' => $review->id,
                'file_path' => 'app/WithoutParent.php',
                'line_number' => 20,
                'severity' => FindingSeverity::Medium->value,
                'status' => FindingStatus::Open->value,
                'title' => 'New finding',
                'evidence' => 'Evidence B',
                'recommendation' => 'Fix B',
                'rule_id' => 'RULE-B',
                'fingerprint' => 'fp-new',
                'source' => 'ruff',
                'is_blocking' => false,
                'objective_id' => null,
                'objective_impact' => null,
                // intentionally omit parent_finding_id — reproduces staging SQLSTATE[21S01]
            ],
        ];

        $created = app(FindingRepository::class)->createMany($rows);

        $this->assertCount(2, $created);
        $this->assertDatabaseHas('findings', [
            'review_id' => $review->id,
            'file_path' => 'app/WithParent.php',
            'parent_finding_id' => $parent->id,
        ]);
        $this->assertDatabaseHas('findings', [
            'review_id' => $review->id,
            'file_path' => 'app/WithoutParent.php',
            'parent_finding_id' => null,
        ]);
    }

    public function test_worker_result_submit_succeeds_when_some_findings_link_to_parents(): void
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->create(['project_id' => $project->id]);
        $priorReview = Review::factory()->create(['repository_id' => $repository->id]);

        $fingerprint = app(RedactionService::class)->fingerprint(
            'stripe-access-token',
            'database/seeders/DemoDataSeeder.php',
            301,
            'Hardcoded Secret Leaked'
        );

        Finding::factory()->create([
            'review_id' => $priorReview->id,
            'fingerprint' => $fingerprint,
            'status' => FindingStatus::Open,
            'rule_id' => 'stripe-access-token',
            'file_path' => 'database/seeders/DemoDataSeeder.php',
            'line_number' => 301,
            'title' => 'Hardcoded Secret Leaked',
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $repository->id,
            'trigger_type' => 'manual_review_now',
            'commit_sha' => 'abc123def456',
            'idempotency_key' => 'bulk-insert-regression-'.uniqid(),
            'status' => ReviewJobStatus::Processing->value,
            'payload' => ['event' => 'manual'],
            'started_at' => now(),
        ]);

        $token = (string) config('services.worker.token');

        $response = $this->postJson(
            "/api/v1/worker/jobs/{$job->id}/result",
            [
                'status' => ReviewStatus::Failed->value,
                'summary' => 'Mixed parent linkage regression payload.',
                'tools_used' => ['gitleaks', 'ruff'],
                'findings' => [
                    [
                        'severity' => FindingSeverity::High->value,
                        'file_path' => 'database/seeders/DemoDataSeeder.php',
                        'line_number' => 301,
                        'rule_id' => 'stripe-access-token',
                        'title' => 'Hardcoded Secret Leaked',
                        'evidence' => 'Potential hardcoded secret.',
                        'recommendation' => 'Remove secret.',
                        'source' => 'gitleaks',
                        'is_blocking' => true,
                        'fingerprint' => $fingerprint,
                    ],
                    [
                        'severity' => FindingSeverity::High->value,
                        'file_path' => 'worker-engine/analyzers/objective_compliance.py',
                        'line_number' => 5,
                        'rule_id' => 'UP035',
                        'title' => 'Ruff: UP035',
                        'evidence' => '`typing.Dict` is deprecated, use `dict` instead',
                        'recommendation' => 'Apply the Ruff-recommended fix.',
                        'source' => 'ruff',
                        'is_blocking' => true,
                    ],
                ],
            ],
            ['Authorization' => 'Bearer '.$token]
        );

        $response->assertOk()->assertJson(['success' => true, 'findings_count' => 2]);
        $this->assertDatabaseCount('findings', 3); // 1 prior + 2 new
    }
}
