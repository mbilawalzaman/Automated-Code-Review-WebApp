<?php

namespace Tests\Feature\Database;

use App\Models\Finding;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Review;
use \App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_schema_has_required_tables(): void
    {
        $tables = [
            'users',
            'projects',
            'repositories',
            'branches',
            'reviews',
            'findings',
            'review_jobs',
            'audit_logs',
            'integrations',
            'reports',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasTable($table),
                "Failed asserting that table [{$table}] exists in database."
            );
        }
    }

    public function test_project_repository_relationship(): void
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->for($project)->create();

        $this->assertTrue($project->repositories->contains($repository));
        $this->assertTrue($repository->project->is($project));
    }

    public function test_review_finding_relationship(): void
    {
        $review = Review::factory()->create();
        $finding = Finding::factory()->for($review)->create();

        $this->assertTrue($review->findings->contains($finding));
        $this->assertTrue($finding->review->is($review));
    }

    public function test_dashboard_summary_counts_reflect_database_records(): void
    {
        $projects = Project::factory()->count(2)->create();
        $repository = Repository::factory()->for($projects->first())->create();
        Repository::factory()->for($projects->last())->count(2)->create();

        $reviews = Review::factory()->count(4)->for($repository)->create();
        Finding::factory()->count(5)->for($reviews->first())->create();

        $response = $this->actingAs(User::factory()->superAdmin()->create([
            'email_verified_at' => now(),
        ]))->get(route('admin.dashboard'));

        $response->assertOk();
        $summary = $response->viewData('summary');
        $this->assertEquals(2, $summary['core']['projects']);
        $this->assertEquals(3, $summary['core']['repositories']);
        $this->assertEquals(4, $summary['core']['reviews']);
        $this->assertEquals(5, $summary['core']['findings']);
    }
}
