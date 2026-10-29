<?php

namespace Tests\Feature\Admin;

use App\Enums\FindingSeverity;
use App\Models\Finding;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;

class DataExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function streamedContent($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_reviews_can_be_exported_as_csv(): void
    {
        $auditor = User::factory()->securityAuditor()->create(['email_verified_at' => now()]);
        $repository = Repository::factory()->create(['full_name' => 'acme/export-repo']);
        Review::factory()->create([
            'repository_id' => $repository->id,
            'commit_sha' => 'fedcba9876543210fedcba9876543210fedcba98',
        ]);

        $response = $this->actingAs($auditor)->get(route('admin.exports.reviews', ['format' => 'csv']));
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $this->streamedContent($response->baseResponse);

        $this->assertStringContainsString('repository,branch,commit_sha', $csv);
        $this->assertStringContainsString('acme/export-repo', $csv);
    }

    public function test_findings_export_includes_objective_context_as_json(): void
    {
        $auditor = User::factory()->securityAuditor()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create();
        $repository = Repository::factory()->create(['project_id' => $project->id]);
        $review = Review::factory()->create(['repository_id' => $repository->id]);

        $objective = Objective::query()->create([
            'project_id' => $project->id,
            'code' => 'SEC-001',
            'title' => 'Secrets must never be committed',
            'is_mandatory' => true,
            'is_approved' => true,
        ]);

        Finding::factory()->create([
            'review_id' => $review->id,
            'objective_id' => $objective->id,
            'objective_impact' => 'Hardcoded token added to config.',
            'severity' => FindingSeverity::High,
        ]);

        $response = $this->actingAs($auditor)->get(route('admin.exports.findings', ['format' => 'json']));
        $response->assertOk();

        $rows = json_decode($this->streamedContent($response->baseResponse), true);

        $this->assertIsArray($rows);
        $this->assertSame('SEC-001', $rows[0]['objective_code']);
        $this->assertSame('Hardcoded token added to config.', $rows[0]['objective_impact']);
    }

    public function test_findings_export_honours_repository_filter(): void
    {
        $auditor = User::factory()->securityAuditor()->create(['email_verified_at' => now()]);

        $wanted = Repository::factory()->create(['full_name' => 'acme/wanted']);
        $other = Repository::factory()->create(['full_name' => 'acme/other']);

        Finding::factory()->create(['review_id' => Review::factory()->create(['repository_id' => $wanted->id])->id]);
        Finding::factory()->create(['review_id' => Review::factory()->create(['repository_id' => $other->id])->id]);

        $response = $this->actingAs($auditor)->get(route('admin.exports.findings', [
            'format' => 'json',
            'repository_id' => $wanted->id,
        ]));

        $rows = json_decode($this->streamedContent($response->baseResponse), true);

        $this->assertCount(1, $rows);
        $this->assertSame('acme/wanted', $rows[0]['repository']);
    }

    public function test_developer_cannot_export_reviews(): void
    {
        $developer = User::factory()->developer()->create(['email_verified_at' => now()]);

        $this->actingAs($developer)->get(route('admin.exports.reviews'))->assertForbidden();
    }

    public function test_developer_cannot_export_findings_without_dashboard_access(): void
    {
        $developer = User::factory()->developer()->create(['email_verified_at' => now()]);

        $this->actingAs($developer)->get(route('admin.exports.findings'))->assertForbidden();
    }
}
