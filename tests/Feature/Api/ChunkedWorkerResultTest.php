<?php

namespace Tests\Feature\Api;

use App\Enums\FindingSeverity;
use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Models\ReviewJobResultChunk;
use Tests\TestCase;

class ChunkedWorkerResultTest extends TestCase
{
    protected Repository $repository;

    protected string $workerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerToken = (string) config('services.worker.token');

        $project = Project::create([
            'name' => 'Chunked Result Project',
            'slug' => 'chunked-result-project',
            'description' => 'Chunked result ingest tests',
        ]);

        $this->repository = Repository::create([
            'project_id' => $project->id,
            'name' => 'chunked-repo',
            'full_name' => 'AlwaysWinningForever/chunked-repo',
            'default_branch' => 'main',
            'status' => RepositoryStatus::Active->value,
        ]);
    }

    public function test_chunked_begin_chunks_complete_ingests_findings(): void
    {
        $job = ReviewJob::create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_push',
            'commit_sha' => 'abcdef1234567890abcdef1234567890abcdef12',
            'idempotency_key' => 'chunked_key_1',
            'status' => ReviewJobStatus::Processing->value,
            'started_at' => now(),
            'payload' => ['event' => 'push'],
        ]);

        $auth = ['Authorization' => 'Bearer '.$this->workerToken];

        $begin = $this->postJson("/api/v1/worker/jobs/{$job->id}/result/begin", [
            'status' => ReviewStatus::PassedWithWarnings->value,
            'summary' => 'Chunked review completed.',
            'author' => 'Worker',
            'pull_request_title' => 'Large scan',
            'tools_used' => ['ruff', 'semgrep'],
            'findings_total' => 3,
            'chunk_size' => 2,
        ], $auth);

        $begin->assertOk()->assertJson([
            'success' => true,
            'chunk_size' => 2,
            'findings_total' => 3,
        ]);
        $this->assertNotEmpty($begin->json('review_id'));

        $chunk0 = $this->postJson("/api/v1/worker/jobs/{$job->id}/result/chunks", [
            'chunk_index' => 0,
            'findings' => [
                $this->findingPayload('Finding A', 'a.py', 1),
                $this->findingPayload('Finding B', 'b.py', 2),
            ],
        ], $auth);
        $chunk0->assertOk()->assertJson([
            'chunk_index' => 0,
            'findings_in_chunk' => 2,
            'findings_inserted' => 2,
        ]);
        $this->assertSame(2, Finding::query()->count());

        // Idempotent re-post of same chunk index — no duplicate inserts.
        $chunk0Again = $this->postJson("/api/v1/worker/jobs/{$job->id}/result/chunks", [
            'chunk_index' => 0,
            'findings' => [
                $this->findingPayload('Finding A', 'a.py', 1),
                $this->findingPayload('Finding B', 'b.py', 2),
            ],
        ], $auth);
        $chunk0Again->assertOk()->assertJson(['findings_inserted' => 0]);
        $this->assertSame(1, ReviewJobResultChunk::where('review_job_id', $job->id)->count());
        $this->assertSame(2, Finding::query()->count());

        $chunk1 = $this->postJson("/api/v1/worker/jobs/{$job->id}/result/chunks", [
            'chunk_index' => 1,
            'findings' => [
                $this->findingPayload('Finding C', 'c.py', 3),
            ],
        ], $auth);
        $chunk1->assertOk()->assertJson(['chunks_received' => 2, 'findings_in_chunk' => 1]);
        $this->assertSame(3, Finding::query()->count());

        $complete = $this->postJson("/api/v1/worker/jobs/{$job->id}/result/complete", [], $auth);
        $complete->assertOk()->assertJson([
            'success' => true,
            'findings_count' => 3,
        ]);

        $this->assertDatabaseHas('review_jobs', [
            'id' => $job->id,
            'status' => ReviewJobStatus::Completed->value,
        ]);

        $this->assertSame(3, Finding::query()->count());
        $this->assertSame(0, ReviewJobResultChunk::query()->count());
    }

    public function test_chunk_without_begin_returns_unprocessable(): void
    {
        $job = ReviewJob::create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_push',
            'commit_sha' => 'bbbbbb1234567890abcdef1234567890abcdef12',
            'idempotency_key' => 'chunked_key_2',
            'status' => ReviewJobStatus::Processing->value,
            'started_at' => now(),
            'payload' => ['event' => 'push'],
        ]);

        $response = $this->postJson("/api/v1/worker/jobs/{$job->id}/result/chunks", [
            'chunk_index' => 0,
            'findings' => [$this->findingPayload('Orphan', 'x.py', 1)],
        ], ['Authorization' => 'Bearer '.$this->workerToken]);

        $response->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function findingPayload(string $title, string $path, int $line): array
    {
        return [
            'severity' => FindingSeverity::Medium->value,
            'file_path' => $path,
            'line_number' => $line,
            'title' => $title,
            'evidence' => 'Evidence for '.$title,
            'recommendation' => 'Fix it',
            'rule_id' => 'TEST-001',
            'source' => 'ruff',
            'is_blocking' => false,
        ];
    }
}
