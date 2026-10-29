<?php

namespace Tests\Feature\Api;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\SrsDocumentStatus;
use App\Models\Finding;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Models\SrsDocument;
use Tests\TestCase;

class ObjectiveAwareReviewTest extends TestCase
{
    protected Repository $repository;

    protected SrsDocument $document;

    protected string $workerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerToken = (string) config('services.worker.token');

        $project = Project::factory()->create();
        $this->repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'acme/objective-repo',
            'status' => RepositoryStatus::Active->value,
        ]);

        $this->document = SrsDocument::query()->create([
            'project_id' => $project->id,
            'repository_id' => $this->repository->id,
            'title' => 'Objective SRS',
            'version' => 1,
            'source_type' => 'upload',
            'status' => SrsDocumentStatus::Approved,
        ]);
    }

    protected function objective(array $attributes = []): Objective
    {
        return Objective::query()->create(array_merge([
            'project_id' => $this->repository->project_id,
            'srs_document_id' => $this->document->id,
            'code' => 'SEC-001',
            'title' => 'Secrets must never be committed',
            'is_mandatory' => true,
            'is_approved' => true,
            'sort_order' => 1,
        ], $attributes));
    }

    protected function pendingJob(): ReviewJob
    {
        return ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_push',
            'commit_sha' => 'abc1234567890abcdef1234567890abcdef12345',
            'idempotency_key' => 'objective_job_' . uniqid(),
            'status' => ReviewJobStatus::Pending->value,
            'payload' => json_encode(['event' => 'push']),
        ]);
    }

    protected function claimJob(): array
    {
        return $this->postJson('/api/v1/worker/jobs/next', [], [
            'Authorization' => 'Bearer ' . $this->workerToken,
        ])->json();
    }

    public function test_claimed_job_carries_approved_objectives_to_the_worker(): void
    {
        $approved = $this->objective();
        $this->objective(['code' => 'SEC-002', 'title' => 'Pending requirement', 'is_approved' => false]);

        $this->pendingJob();
        $payload = $this->claimJob();

        $objectives = $payload['job']['objectives'];

        $this->assertCount(1, $objectives, 'Only approved requirements may reach the worker.');
        $this->assertSame($approved->id, $objectives[0]['id']);
        $this->assertSame('SEC-001', $objectives[0]['code']);
        $this->assertTrue($objectives[0]['is_mandatory']);
    }

    public function test_objectives_are_empty_when_no_revision_is_approved(): void
    {
        $this->document->update(['status' => SrsDocumentStatus::PendingApproval]);
        $this->objective();
        $this->pendingJob();

        $this->assertSame([], $this->claimJob()['job']['objectives']);
    }

    public function test_superseded_revision_objectives_are_not_sent(): void
    {
        $this->objective();
        $this->document->update([
            'status' => SrsDocumentStatus::Superseded,
            'superseded_at' => now(),
        ]);
        $this->pendingJob();

        $this->assertSame([], $this->claimJob()['job']['objectives']);
    }

    public function test_project_wide_revision_applies_when_repository_has_none(): void
    {
        $this->document->update(['repository_id' => null]);
        $objective = $this->objective();
        $this->pendingJob();

        $objectives = $this->claimJob()['job']['objectives'];

        $this->assertCount(1, $objectives);
        $this->assertSame($objective->id, $objectives[0]['id']);
    }

    public function test_mandatory_objective_violation_is_persisted_and_blocks_merging(): void
    {
        $objective = $this->objective();
        $job = $this->pendingJob();
        $this->claimJob();

        $this->postJson("/api/v1/worker/jobs/{$job->id}/result", [
            'status' => 'failed',
            'summary' => 'Objective review found a mandatory requirement breach.',
            'objectives_evaluated' => 1,
            'findings' => [[
                'title' => 'Objective SEC-001 not met',
                'severity' => 'medium',
                'file_path' => 'src/config.js',
                'line_number' => 12,
                'evidence' => 'API key added directly to the config file.',
                'srs_objective_id' => $objective->id,
                'objective_impact' => 'API key added directly to the config file.',
                'source' => 'objective-compliance',
            ]],
        ], ['Authorization' => 'Bearer ' . $this->workerToken])->assertOk();

        $finding = Finding::query()->latest('id')->firstOrFail();

        $this->assertSame($objective->id, $finding->objective_id);
        $this->assertSame('API key added directly to the config file.', $finding->objective_impact);
        $this->assertTrue(
            (bool) $finding->is_blocking,
            'A confirmed mandatory objective breach must block merging even at medium severity.'
        );
    }

    public function test_advisory_objective_violation_does_not_force_blocking(): void
    {
        $objective = $this->objective(['code' => 'SEC-050', 'is_mandatory' => false]);
        $job = $this->pendingJob();
        $this->claimJob();

        $this->postJson("/api/v1/worker/jobs/{$job->id}/result", [
            'status' => 'passed_with_warnings',
            'summary' => 'Advisory requirement not fully met.',
            'findings' => [[
                'title' => 'Objective SEC-050 not met',
                'severity' => 'low',
                'file_path' => 'src/util.js',
                'line_number' => 4,
                'srs_objective_id' => $objective->id,
                'source' => 'objective-compliance',
            ]],
        ], ['Authorization' => 'Bearer ' . $this->workerToken])->assertOk();

        $finding = Finding::query()->latest('id')->firstOrFail();

        $this->assertSame($objective->id, $finding->objective_id);
        $this->assertFalse((bool) $finding->is_blocking);
    }

    public function test_objective_ids_outside_the_approved_checklist_are_ignored(): void
    {
        $this->objective();
        $foreign = Objective::query()->create([
            'project_id' => $this->repository->project_id,
            'code' => 'OTHER-001',
            'title' => 'Requirement from an unapproved document',
            'is_mandatory' => true,
            'is_approved' => false,
        ]);

        $job = $this->pendingJob();
        $this->claimJob();

        $this->postJson("/api/v1/worker/jobs/{$job->id}/result", [
            'status' => 'failed',
            'summary' => 'Worker reported an objective it was never given.',
            'findings' => [[
                'title' => 'Spoofed objective finding',
                'severity' => 'low',
                'file_path' => 'src/spoof.js',
                'line_number' => 1,
                'srs_objective_id' => $foreign->id,
                'objective_impact' => 'Should be discarded.',
                'source' => 'objective-compliance',
            ]],
        ], ['Authorization' => 'Bearer ' . $this->workerToken])->assertOk();

        $finding = Finding::query()->latest('id')->firstOrFail();

        $this->assertNull($finding->objective_id);
        $this->assertNull($finding->objective_impact);
        $this->assertFalse((bool) $finding->is_blocking);
    }
}
