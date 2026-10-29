<?php

namespace App\Services\Api;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\ReviewJobStatus;
use App\Models\Review;
use App\Models\ReviewJob;
use App\Models\ReviewJobResultChunk;
use App\Services\Admin\AuditLogService;
use App\Services\Admin\SrsLifecycleService;
use App\Support\WorkerFindingNormalizer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class WorkerService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected ReviewJobFinalizationService $finalizationService,
        protected SrsLifecycleService $srsLifecycleService,
        protected ExecutionAuthorizationService $executionAuthorizationService,
    ) {
    }

    /**
     * Atomically claim the oldest pending ReviewJob.
     *
     * @return array
     */
    public function fetchNextJob(): array
    {
        return DB::transaction(function () {
            $job = ReviewJob::where('status', ReviewJobStatus::Pending->value)
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if (! $job) {
                return [
                    'has_job' => false,
                    'job' => null,
                    'message' => 'No pending jobs available',
                ];
            }

            $job->update([
                'status' => ReviewJobStatus::Processing->value,
                'started_at' => now(),
                'attempts' => $job->attempts + 1,
            ]);

            $repository = $job->repository;
            $payloadData = is_string($job->payload) ? json_decode($job->payload, true) : ($job->payload ?? []);

            // Approved requirements drive objective-aware review; an empty list means
            // the worker falls back to technical/security-only review (SRS-004).
            $objectives = $repository
                ? $this->srsLifecycleService->objectivePayloadFor($repository)
                : [];

            $authorization = $this->executionAuthorizationService->forJob($job);

            $this->auditLogService->record(
                category: AuditCategory::Worker,
                eventType: AuditEventType::JobClaimed,
                action: "Worker engine claimed Review Job #{$job->id} for repository {$repository?->full_name}",
                severity: AuditSeverity::Info,
                target: $job,
                afterState: [
                    'status' => ReviewJobStatus::Processing->value,
                    'attempts' => $job->attempts,
                    'execution_authorized' => $authorization['authorized'],
                    'execution_authorization_reason' => $authorization['reason'],
                ],
                reason: 'Worker Engine claimed pending review job'
            );

            return [
                'has_job' => true,
                'job' => [
                    'id' => $job->id,
                    'repository_id' => $job->repository_id,
                    'repository' => [
                        'name' => $repository?->name ?? 'Unknown',
                        'full_name' => $repository?->full_name ?? 'Unknown',
                        'default_branch' => $repository?->default_branch ?? 'main',
                        'clone_url' => $repository?->cloneUrl(),
                        'html_url' => $repository?->htmlUrl(),
                    ],
                    'commit_sha' => $job->commit_sha,
                    'pull_request_number' => $job->pull_request_number,
                    'github_check_run_id' => $job->github_check_run_id,
                    'trello_card_id' => $job->trello_card_id,
                    'trello_comment_id' => $job->trello_comment_id,
                    'trigger_type' => $job->trigger_type,
                    'payload' => $payloadData,
                    'objectives' => $objectives,
                    'execution_authorized' => $authorization['authorized'],
                    'execution_authorization_reason' => $authorization['reason'],
                    'changed_coverage_min_percent' => (float) config('worker.changed_coverage_min_percent', 70),
                    'result_chunk_size' => (int) config('worker.result_chunk_size', 100),
                    'result_single_shot_max' => (int) config('worker.result_single_shot_max', 75),
                ],
            ];
        });
    }

    /**
     * Submit review execution results and findings for a claimed job.
     *
     * @param  int  $jobId
     * @param  array  $result
     * @return array
     *
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
     */
    public function submitJobResult(int $jobId, array $result): array
    {
        $finalized = $this->finalizationService->finalizeJob($jobId, $result);

        return [
            'success' => true,
            'message' => 'Review job result successfully ingested',
            'review_id' => $finalized['review']->id,
            'findings_count' => $finalized['findings_count'],
        ];
    }

    /**
     * Begin a chunked result ingest session: create Review shell, clear prior chunks.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function beginJobResult(int $jobId, array $meta): array
    {
        return DB::transaction(function () use ($jobId, $meta) {
            $job = $this->lockOpenJob($jobId);

            $payload = is_array($job->payload) ? $job->payload : [];
            ReviewJobResultChunk::query()->where('review_job_id', $job->id)->delete();

            $chunkSize = (int) ($meta['chunk_size'] ?? config('worker.result_chunk_size', 100));
            $chunkSize = max(1, min(500, $chunkSize));

            $review = $this->finalizationService->beginChunkedReview($job, $meta);

            $payload['result_ingest'] = [
                'started_at' => now()->toIso8601String(),
                'findings_total' => (int) ($meta['findings_total'] ?? 0),
                'chunk_size' => $chunkSize,
                'review_id' => $review->id,
                'received_chunk_indexes' => [],
                'persisted_chunk_indexes' => [],
                'findings_persisted' => 0,
                'meta' => [
                    'status' => $meta['status'] ?? 'passed',
                    'summary' => $meta['summary'] ?? 'Review completed successfully.',
                    'pull_request_title' => $meta['pull_request_title'] ?? null,
                    'author' => $meta['author'] ?? null,
                    'tools_used' => $meta['tools_used'] ?? null,
                    'github_check_run_id' => $meta['github_check_run_id'] ?? null,
                    'error_message' => $meta['error_message'] ?? null,
                    'objectives_evaluated' => $meta['objectives_evaluated'] ?? null,
                    'stacks' => $meta['stacks'] ?? null,
                    'test_summary' => $meta['test_summary'] ?? null,
                    'coverage_percentage' => $meta['coverage_percentage'] ?? null,
                    'changed_code_coverage' => $meta['changed_code_coverage'] ?? null,
                    'execution_authorized' => $meta['execution_authorized'] ?? null,
                    'change_classes' => $meta['change_classes'] ?? null,
                    'impact_analysis' => $meta['impact_analysis'] ?? null,
                    'tool_executions' => $meta['tool_executions'] ?? null,
                    'findings_metrics' => $meta['findings_metrics'] ?? null,
                ],
            ];

            $job->payload = $payload;
            $job->save();

            return [
                'success' => true,
                'message' => 'Chunked result ingest session started',
                'job_id' => $job->id,
                'review_id' => $review->id,
                'chunk_size' => $chunkSize,
                'findings_total' => (int) ($meta['findings_total'] ?? 0),
            ];
        });
    }

    /**
     * Persist one findings chunk immediately (idempotent by chunk_index).
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    public function appendJobResultChunk(int $jobId, int $chunkIndex, array $findings): array
    {
        return DB::transaction(function () use ($jobId, $chunkIndex, $findings) {
            $job = $this->lockOpenJob($jobId);
            $payload = is_array($job->payload) ? $job->payload : [];
            $ingest = $payload['result_ingest'] ?? null;

            if (! is_array($ingest)) {
                throw new UnprocessableEntityHttpException(
                    "ReviewJob #{$jobId} has no active chunked result ingest session. Call result/begin first."
                );
            }

            $reviewId = (int) ($ingest['review_id'] ?? 0);
            $review = Review::query()->where('id', $reviewId)->where('review_job_id', $job->id)->first();
            if (! $review) {
                throw new UnprocessableEntityHttpException(
                    "ReviewJob #{$jobId} is missing its Review shell for chunked ingest."
                );
            }

            $normalized = WorkerFindingNormalizer::normalizeList($findings);
            $received = array_map('intval', $ingest['received_chunk_indexes'] ?? []);
            $persisted = array_map('intval', $ingest['persisted_chunk_indexes'] ?? []);

            $existing = ReviewJobResultChunk::query()
                ->where('review_job_id', $job->id)
                ->where('chunk_index', $chunkIndex)
                ->first();

            if ($existing) {
                $existing->update(['findings' => $normalized]);
            } else {
                ReviewJobResultChunk::query()->create([
                    'review_job_id' => $job->id,
                    'chunk_index' => $chunkIndex,
                    'findings' => $normalized,
                ]);
            }

            $inserted = 0;
            if (! in_array($chunkIndex, $persisted, true)) {
                $inserted = $this->finalizationService->persistFindingsChunk($job, $review, $normalized);
                $persisted[] = $chunkIndex;
                sort($persisted);
                $ingest['findings_persisted'] = (int) ($ingest['findings_persisted'] ?? 0) + $inserted;
            }

            if (! in_array($chunkIndex, $received, true)) {
                $received[] = $chunkIndex;
                sort($received);
            }

            $ingest['received_chunk_indexes'] = $received;
            $ingest['persisted_chunk_indexes'] = $persisted;
            $payload['result_ingest'] = $ingest;
            $job->payload = $payload;
            $job->save();

            return [
                'success' => true,
                'message' => 'Findings chunk accepted',
                'job_id' => $job->id,
                'review_id' => $review->id,
                'chunk_index' => $chunkIndex,
                'findings_in_chunk' => count($normalized),
                'findings_inserted' => $inserted,
                'chunks_received' => count($received),
                'findings_persisted' => (int) ($ingest['findings_persisted'] ?? 0),
            ];
        });
    }

    /**
     * Finalize chunked ingest: findings are already in DB; only reconcile + terminal + side effects.
     *
     * @return array<string, mixed>
     */
    public function completeJobResult(int $jobId): array
    {
        $metaAndExpected = DB::transaction(function () use ($jobId) {
            $job = $this->lockOpenJob($jobId);
            $payload = is_array($job->payload) ? $job->payload : [];
            $ingest = $payload['result_ingest'] ?? null;

            if (! is_array($ingest)) {
                throw new UnprocessableEntityHttpException(
                    "ReviewJob #{$jobId} has no active chunked result ingest session."
                );
            }

            return [
                'meta' => is_array($ingest['meta'] ?? null) ? $ingest['meta'] : [],
                'expected_total' => (int) ($ingest['findings_total'] ?? 0),
                'findings_persisted' => (int) ($ingest['findings_persisted'] ?? 0),
            ];
        });

        $finalized = $this->finalizationService->finalizeChunkedJob(
            $jobId,
            $metaAndExpected['meta']
        );

        ReviewJobResultChunk::query()->where('review_job_id', $jobId)->delete();

        $job = ReviewJob::query()->find($jobId);
        if ($job) {
            $payload = is_array($job->payload) ? $job->payload : [];
            unset($payload['result_ingest']);
            $job->payload = $payload;
            $job->save();
        }

        return [
            'success' => true,
            'message' => 'Chunked review job result successfully ingested',
            'review_id' => $finalized['review']->id,
            'findings_count' => $finalized['findings_count'],
            'findings_expected' => $metaAndExpected['expected_total'],
            'findings_persisted_during_chunks' => $metaAndExpected['findings_persisted'],
        ];
    }

    /**
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     */
    protected function lockOpenJob(int $jobId): ReviewJob
    {
        /** @var ReviewJob|null $job */
        $job = ReviewJob::query()->where('id', $jobId)->lockForUpdate()->first();

        if (! $job) {
            throw new NotFoundHttpException("ReviewJob #{$jobId} not found.");
        }

        if (in_array($job->status, [
            ReviewJobStatus::Completed,
            ReviewJobStatus::Failed,
            ReviewJobStatus::Cancelled,
        ], true)) {
            throw new ConflictHttpException(
                "ReviewJob #{$jobId} has already been finalized or timed out (Current status: {$job->status->value})."
            );
        }

        if (! in_array($job->status, [ReviewJobStatus::Processing, ReviewJobStatus::Running], true)) {
            throw new ConflictHttpException(
                "ReviewJob #{$jobId} is not accepting results (Current status: {$job->status->value})."
            );
        }

        return $job;
    }
}
