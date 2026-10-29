<?php

namespace Tests\Feature\Admin;

use App\Models\Finding;
use App\Models\Project;
use App\Models\Report;
use App\Models\Repository;
use App\Models\Review;
use App\Models\User;
use App\Services\Admin\ReportPdfService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $developer;
    private Report $report;
    private Review $review;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');

        $this->developer = User::factory()->create();
        $this->developer->assignRole('developer');

        $project = Project::factory()->create();
        $repository = Repository::factory()->create(['project_id' => $project->id]);

        $this->review = Review::factory()->create([
            'repository_id' => $repository->id,
            'summary' => 'Feature review completed with zero vulnerabilities.',
            'tools_used' => ['semgrep', 'gitleaks', 'ollama/codellama:7b'],
        ]);

        $this->report = Report::query()->create([
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'review_id' => $this->review->id,
            'type' => 'review_summary',
            'format' => 'pdf',
            'file_path' => "reports/{$repository->name}/review-{$this->review->id}.pdf",
        ]);

        Storage::fake('local');
    }

    public function test_super_admin_can_fetch_reports_datatable_with_action_buttons(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.reports.data'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $this->report->id);
        $response->assertSee('Download PDF');
        $response->assertSee('View PDF');
    }

    public function test_user_can_download_pdf_report_and_trigger_lazy_generation(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.reports.download', $this->report->id));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', "attachment; filename=\"review-report-{$this->report->id}.pdf\"");

        $pdfContent = $response->getContent();
        // Assert valid PDF binary header magic bytes
        $this->assertStringContainsString('%PDF-', $pdfContent);

        $this->report->refresh();
        $expectedPath = 'reports/'.$this->review->repository->name
            .'/review-'.$this->review->id.'-v'.ReportPdfService::TEMPLATE_VERSION.'.pdf';
        $this->assertSame($expectedPath, $this->report->file_path);
        $this->assertTrue(Storage::disk('local')->exists($expectedPath));
    }

    public function test_user_can_stream_pdf_report_inline(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.reports.stream', $this->report->id));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'inline');

        $pdfContent = $response->getContent();
        // Assert valid PDF binary header magic bytes
        $this->assertStringContainsString('%PDF-', $pdfContent);
    }

    public function test_pdf_generation_includes_long_finding_evidence_without_error(): void
    {
        Finding::factory()->create([
            'review_id' => $this->review->id,
            'title' => 'Hardcoded Secret Leaked: Found a Stripe Access Token',
            'file_path' => 'worker-engine/tests/fixtures/very/long/nested/path/staging_review_probe.py',
            'line_number' => 8,
            'evidence' => 'Potential hardcoded secret or API credential detected (stripe-access-token). '
                .str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789/', 20),
            'recommendation' => 'Remove the intentional probe fixture after staging validation completes.',
        ]);

        $path = app(ReportPdfService::class)->generateForReview($this->review->fresh(['repository', 'findings']));
        $pdfContent = Storage::disk('local')->get($path);

        $this->assertStringContainsString('%PDF-', $pdfContent);
        $this->assertGreaterThan(1500, strlen($pdfContent));
    }

    public function test_developer_without_report_permission_is_denied(): void
    {
        $response = $this->actingAs($this->developer)
            ->get(route('admin.reports.download', $this->report->id));

        $response->assertStatus(403);
    }

    public function test_streaming_orphaned_report_without_review_redirects_with_error(): void
    {
        // Delete review to simulate orphaned report without pre-rendered file on disk
        $this->review->delete();

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.reports.stream', $this->report->id));

        $response->assertRedirect(route('admin.reports.index'));
        $response->assertSessionHasErrors(['report']);
    }
}
