<?php

namespace Tests\Feature\Admin;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewJob;
use App\Models\User;
use App\Services\Api\ReviewJobFinalizationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeImpactAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_finalization_persists_change_classes_impact_and_tool_executions(): void
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'status' => RepositoryStatus::Active,
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $repository->id,
            'status' => ReviewJobStatus::Processing,
            'trigger_type' => 'webhook_push',
            'idempotency_key' => 'impact-'.uniqid(),
            'commit_sha' => str_repeat('a', 40),
            'started_at' => now(),
            'payload' => ['author' => 'dev', 'branch_name' => 'feature/x'],
        ]);

        $payload = [
            'status' => 'failed',
            'summary' => 'Destructive migration and auth impact detected.',
            'pull_request_title' => 'Drop users.legacy_token',
            'author' => 'dev',
            'tools_used' => ['migration-analyzer', 'impact-analyzer'],
            'change_classes' => ['destructive_migration', 'auth'],
            'impact_analysis' => [
                'affected_modules' => ['database', 'security'],
                'database_tables' => ['users'],
                'change_classes' => ['destructive_migration', 'auth'],
            ],
            'tool_executions' => [
                [
                    'tool' => 'migration-analyzer',
                    'version' => '1.0.0',
                    'duration_ms' => 12,
                    'exit_code' => 0,
                ],
            ],
            'findings' => [
                [
                    'title' => 'Destructive migration detected (DROP COLUMN)',
                    'severity' => 'critical',
                    'file_path' => 'database/migrations/2026_07_31_drop.php',
                    'line_start' => 1,
                    'description' => 'CHG-001',
                    'rule_id' => 'CHG-001',
                    'is_blocking' => true,
                    'tool_name' => 'migration-analyzer',
                ],
            ],
        ];

        $result = app(ReviewJobFinalizationService::class)->finalizeJob($job->id, $payload);
        /** @var Review $review */
        $review = $result['review'];

        $this->assertSame(['destructive_migration', 'auth'], $review->change_classes);
        $this->assertSame(['database', 'security'], $review->impact_analysis['affected_modules'] ?? null);
        $this->assertSame('users', ($review->impact_analysis['database_tables'] ?? [])[0] ?? null);
        $this->assertSame('migration-analyzer', ($review->tool_executions[0]['tool'] ?? null));
        $this->assertSame(ReviewStatus::Failed, $review->status);
    }

    public function test_review_show_includes_impact_and_provenance(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $review = Review::factory()->create([
            'status' => ReviewStatus::Failed,
            'change_classes' => ['ci_cd'],
            'impact_analysis' => [
                'affected_modules' => ['ci_cd'],
                'ci_cd_pipelines' => ['.github/workflows/deploy.yml'],
            ],
            'tool_executions' => [
                ['tool' => 'impact-analyzer', 'version' => '1.0.0', 'exit_code' => 0],
            ],
        ]);

        $response = $this->actingAs($manager)->getJson(route('admin.reviews.show', $review));

        $response->assertOk();
        $response->assertJsonPath('change_classes.0', 'ci_cd');
        $response->assertJsonPath('impact_analysis.affected_modules.0', 'ci_cd');
        $response->assertJsonPath('tool_executions.0.tool', 'impact-analyzer');
    }
}
