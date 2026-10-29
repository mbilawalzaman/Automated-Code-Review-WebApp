<?php

namespace App\Services\Api;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\FindingSeverity;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Events\ReviewCompleted;
use App\Models\Finding;
use App\Models\Report;
use App\Models\Review;
use App\Models\ReviewJob;
use App\Repositories\Contracts\FindingRepositoryInterface;
use App\Services\Admin\AuditLogService;
use App\Services\Admin\ReportPdfService;
use App\Services\Admin\SrsLifecycleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewJobFinalizationService
{
    public function __construct(
        protected GitHubCheckRunService $checkRunService,
        protected GitHubReviewCommentService $reviewCommentService,
        protected ReportPdfService $reportPdfService,
        protected AuditLogService $auditLogService,
        protected FindingRepositoryInterface $findingRepository,
        protected SrsLifecycleService $srsLifecycleService,
        protected RedactionService $redactionService,
        protected FindingReconciliationService $findingReconciliationService,
    ) {}

    /**
     * Stage 1: Transactionally update DB state, create Review record, set job to terminal state.
     * Stage 2: Post-commit external HTTP dispatches (GitHub Check Run, Trello, PDF).
     *
     * @return array{review: Review, job: ReviewJob, raw_findings: array<int, mixed>, findings_count: int}
     *
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
     */
    public function finalizeTimeout(ReviewJob $job, ?string $reason = null): array
    {
        $reason ??= sprintf(
            'Job timed out after %d minutes in processing state.',
            (int) config('worker.stuck_job_after_minutes', 30)
        );

        $payload = [
            'status' => 'review_error',
            'summary' => $reason,
            'findings' => [],
            'metrics' => [
                'files_analyzed' => 0,
                'lines_reviewed' => 0,
            ],
            'tool_executions' => [],
        ];

        return $this->finalizeJob($job->id, $payload, isTimeout: true, timeoutReason: $reason);
    }

    /**
     * Approved requirement IDs the worker may reference, mapped to whether they are
     * mandatory. Anything outside this set is ignored so a worker cannot attach
     * findings to arbitrary or unapproved objectives.
     *
     * @return array<int, bool>
     */
    protected function allowedObjectiveIds(ReviewJob $job): array
    {
        $repository = $job->repository;

        if (! $repository) {
            return [];
        }

        $document = $this->srsLifecycleService->activeFor($repository);

        if (! $document) {
            return [];
        }

        return $document->objectives()
            ->approved()
            ->pluck('is_mandatory', 'id')
            ->map(fn ($mandatory) => (bool) $mandatory)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<int, bool>  $allowedObjectives
     */
    protected function resolveObjectiveId(array $finding, array $allowedObjectives): ?int
    {
        $candidate = $finding['objective_id'] ?? $finding['srs_objective_id'] ?? null;

        if ($candidate === null || $allowedObjectives === []) {
            return null;
        }

        $candidate = (int) $candidate;

        return array_key_exists($candidate, $allowedObjectives) ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{review: Review, job: ReviewJob, raw_findings: array<int, mixed>, findings_count: int}
     */
    public function finalizeJob(int $jobId, array $payload, bool $isTimeout = false, ?string $timeoutReason = null): array
    {
        // ------------------------------------------------------------------------
        // STAGE 1: Transactional DB state transition with row lock
        // ------------------------------------------------------------------------
        $result = DB::transaction(function () use ($jobId, $payload, $isTimeout, $timeoutReason) {
            /** @var ReviewJob|null $job */
            $job = ReviewJob::query()->where('id', $jobId)->lockForUpdate()->first();

            if (! $job) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("ReviewJob #{$jobId} not found.");
            }

            // Return 409 Conflict if job is already in a terminal state
            if (in_array($job->status, [ReviewJobStatus::Completed, ReviewJobStatus::Failed, ReviewJobStatus::Cancelled], true)) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                    "ReviewJob #{$jobId} has already been finalized or timed out (Current status: {$job->status->value})."
                );
            }

            $rawStatus = strtolower((string) ($payload['status'] ?? 'passed'));
            $reviewStatus = match ($rawStatus) {
                'passed' => ReviewStatus::Passed->value,
                'passed_with_warnings' => ReviewStatus::PassedWithWarnings->value,
                'failed' => ReviewStatus::Failed->value,
                default => ReviewStatus::ReviewError->value,
            };

            if ($isTimeout) {
                $reviewStatus = ReviewStatus::ReviewError->value;
            }

            $summaryText = $payload['summary'] ?? ($isTimeout ? $timeoutReason : 'Review completed successfully.');
            $checkRunId = $payload['github_check_run_id'] ?? $job->github_check_run_id;

            // Create or retrieve existing Review record
            $review = Review::query()->firstOrCreate(
                ['review_job_id' => $job->id],
                [
                    'repository_id' => $job->repository_id,
                    'branch_id' => $job->branch_id,
                    'status' => $reviewStatus,
                    'commit_sha' => $job->commit_sha,
                    'pull_request_number' => $job->pull_request_number,
                    'pull_request_title' => $payload['pull_request_title'] ?? 'Automated Review',
                    'author' => $payload['author'] ?? 'Worker Agent',
                    'started_at' => $job->started_at ?? now(),
                    'completed_at' => Carbon::now(),
                    'summary' => $summaryText,
                    'tools_used' => $payload['tools_used'] ?? ['gitleaks', 'semgrep', 'osv-scanner', 'ollama'],
                    'coverage_percentage' => $payload['coverage_percentage'] ?? null,
                    'changed_code_coverage' => $payload['changed_code_coverage'] ?? null,
                    'test_summary' => $payload['test_summary'] ?? null,
                    'execution_authorized' => array_key_exists('execution_authorized', $payload)
                        ? (bool) $payload['execution_authorized']
                        : null,
                    'detected_stacks' => $payload['stacks'] ?? null,
                    'change_classes' => is_array($payload['change_classes'] ?? null)
                        ? array_values($payload['change_classes'])
                        : null,
                    'impact_analysis' => is_array($payload['impact_analysis'] ?? null)
                        ? $payload['impact_analysis']
                        : null,
                    'tool_executions' => is_array($payload['tool_executions'] ?? null)
                        ? array_values($payload['tool_executions'])
                        : null,
                    'github_check_run_id' => $checkRunId,
                    'trello_card_id' => $job->trello_card_id,
                    'trello_comment_id' => $job->trello_comment_id,
                ]
            );

            // Create findings records. Skipped when the Review already existed so a
            // retried submission cannot duplicate findings.
            $rawFindings = $payload['findings'] ?? [];
            $createdFindingsCount = 0;
            $resolvedFindings = [];
            if ($review->wasRecentlyCreated) {
                $allowedObjectives = $this->allowedObjectiveIds($job);
                $findingsToCreate = [];
                if (! empty($rawFindings) && is_array($rawFindings)) {
                    foreach ($rawFindings as $f) {
                        $severity = $f['severity'] ?? FindingSeverity::Medium->value;
                        $severityEnum = FindingSeverity::tryFrom($severity);
                        $isBlocking = array_key_exists('is_blocking', $f)
                            ? (bool) $f['is_blocking']
                            : ($severityEnum?->blocksMerging() ?? false);

                        $objectiveId = $this->resolveObjectiveId($f, $allowedObjectives);

                        // Confirmed non-compliance with an approved mandatory requirement
                        // blocks merging regardless of the finding's severity (SRS-005).
                        if ($objectiveId !== null && ($allowedObjectives[$objectiveId] ?? false)) {
                            $isBlocking = true;
                        }

                        $title = $f['title'] ?? 'Automated Finding';
                        $filePath = $f['file_path'] ?? 'Unknown';
                        $lineNumber = (int) ($f['line_number'] ?? $f['line_start'] ?? 1);
                        $ruleId = $f['rule_id'] ?? 'RULE-001';
                        $evidence = $this->redactionService->redact(
                            $f['evidence'] ?? $f['description'] ?? 'No evidence provided.'
                        );
                        $recommendation = $this->redactionService->redact(
                            $f['recommendation'] ?? $f['remediation'] ?? null
                        );
                        $objectiveImpact = $objectiveId !== null
                            ? $this->redactionService->redact(
                                $f['objective_impact'] ?? $f['evidence'] ?? $f['description'] ?? null
                            )
                            : null;

                        $fingerprint = $f['fingerprint']
                            ?? $this->redactionService->fingerprint($ruleId, $filePath, $lineNumber, $title);

                        $findingsToCreate[] = [
                            'review_id' => $review->id,
                            'file_path' => $filePath,
                            'line_number' => $lineNumber,
                            'severity' => $severity,
                            'status' => $f['status'] ?? 'open',
                            'title' => $title,
                            'evidence' => $evidence !== '' ? $evidence : 'No evidence provided.',
                            'recommendation' => $recommendation !== '' ? $recommendation : null,
                            'rule_id' => $ruleId,
                            'fingerprint' => $fingerprint,
                            'source' => $f['source'] ?? $f['tool_name'] ?? 'static-analysis',
                            'is_blocking' => $isBlocking,
                            'objective_id' => $objectiveId,
                            'objective_impact' => $objectiveImpact,
                        ];
                    }
                }

                // VPS-side TST-004 enforcement if the worker reported low changed-line coverage
                // without an explicit blocking finding.
                $threshold = (float) config('worker.changed_coverage_min_percent', 70);
                $changedCoverage = $payload['changed_code_coverage'] ?? null;
                if ($changedCoverage !== null && (float) $changedCoverage < $threshold) {
                    $hasCoverageFinding = collect($findingsToCreate)->contains(
                        fn (array $finding) => ($finding['rule_id'] ?? null) === 'TST-004'
                    );

                    if (! $hasCoverageFinding) {
                        $findingsToCreate[] = [
                            'review_id' => $review->id,
                            'file_path' => 'coverage',
                            'line_number' => 1,
                            'severity' => FindingSeverity::High->value,
                            'status' => 'open',
                            'title' => 'Changed-code coverage below threshold',
                            'evidence' => sprintf(
                                'Changed-line coverage is %.1f%% (threshold %.0f%%).',
                                (float) $changedCoverage,
                                $threshold
                            ),
                            'recommendation' => 'Add tests covering the changed lines before merging.',
                            'rule_id' => 'TST-004',
                            'fingerprint' => $this->redactionService->fingerprint(
                                'TST-004',
                                'coverage',
                                1,
                                'Changed-code coverage below threshold'
                            ),
                            'source' => 'coverage',
                            'is_blocking' => true,
                            'objective_id' => null,
                            'objective_impact' => null,
                        ];
                    }

                    if ($reviewStatus !== ReviewStatus::ReviewError->value) {
                        $reviewStatus = ReviewStatus::Failed->value;
                        $review->status = $reviewStatus;
                        $review->save();
                    }
                }

                // Always reconcile so a clean review can auto-close prior open findings (FND-003).
                $reconciled = $this->findingReconciliationService->reconcile($review, $findingsToCreate);
                $findingsToCreate = $reconciled['findings'];
                $resolvedFindings = $reconciled['resolved']->map(fn ($finding) => [
                    'title' => $finding->title,
                    'file_path' => $finding->file_path,
                    'line_number' => $finding->line_number,
                    'severity' => $finding->severity?->value ?? (string) $finding->severity,
                    'fingerprint' => $finding->fingerprint,
                ])->all();

                if ($findingsToCreate !== []) {
                    $createdFindingsCount = count($this->findingRepository->createMany($findingsToCreate));
                }
            }

            // Update ReviewJob status
            $finalJobStatus = ($reviewStatus === ReviewStatus::Failed->value || $reviewStatus === ReviewStatus::ReviewError->value)
                ? ReviewJobStatus::Failed
                : ReviewJobStatus::Completed;

            $job->status = $finalJobStatus;
            $job->completed_at = Carbon::now();
            $job->error_message = $isTimeout ? $timeoutReason : ($payload['error_message'] ?? null);
            $job->save();

            // Log audit record
            $this->auditLogService->record(
                category: AuditCategory::Worker,
                eventType: AuditEventType::ResultSubmitted,
                action: "ReviewJob #{$job->id} finalized as {$reviewStatus}",
                severity: $reviewStatus === ReviewStatus::ReviewError->value ? AuditSeverity::Critical : AuditSeverity::Info,
                target: $review,
                reason: $isTimeout ? 'Terminal timeout sweeping' : 'Worker result submission'
            );

            return [
                'review' => $review,
                'job' => $job,
                'raw_findings' => $rawFindings,
                'findings_count' => $createdFindingsCount,
                'resolved_findings' => $resolvedFindings,
            ];
        });

        $review = $result['review'];
        $job = $result['job'];
        $rawFindings = $result['raw_findings'];
        $resolvedFindings = $result['resolved_findings'] ?? [];

        // ------------------------------------------------------------------------
        // STAGE 2: Post-Commit External Dispatches (GitHub, Trello, PDF, Mail)
        // ------------------------------------------------------------------------
        $pdfPath = null;
        try {
            $pdfPath = $this->reportPdfService->generateForReview($review);
            Report::query()->create([
                'project_id' => $job->repository?->project_id,
                'repository_id' => $job->repository_id,
                'review_id' => $review->id,
                'type' => 'review_summary',
                'format' => 'pdf',
                'file_path' => $pdfPath,
                'metadata' => [
                    'finding_count' => count($rawFindings),
                    'tools_used' => $review->tools_used,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("Post-commit PDF generation failed for Review #{$review->id}: {$e->getMessage()}");
        }

        $detailsUrl = config('app.url') . '/admin/reports';

        try {
            $checkRunId = $payload['github_check_run_id'] ?? $job->github_check_run_id;
            if ($job->repository && $checkRunId) {
                $this->checkRunService->finalizeCheckRun(
                    $job->repository,
                    (int) $checkRunId,
                    $review->status->value ?? (string) $review->status,
                    $review->summary,
                    $rawFindings,
                    $detailsUrl
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Post-commit GitHub Check Run finalization failed for Review #{$review->id}: {$e->getMessage()}");
        }

        try {
            if ($job->repository && $job->pull_request_number) {
                $this->reviewCommentService->postConsolidatedReview(
                    $job->repository,
                    (int) $job->pull_request_number,
                    $job->commit_sha ?? 'HEAD',
                    $review->status->value ?? (string) $review->status,
                    $review->summary,
                    $rawFindings,
                    $detailsUrl,
                    $resolvedFindings
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Post-commit GitHub PR review posting failed for Review #{$review->id}: {$e->getMessage()}");
        }

        try {
            ReviewCompleted::dispatch($review, $rawFindings);
        } catch (\Throwable $e) {
            Log::warning("Post-commit ReviewCompleted event dispatch failed for Review #{$review->id}: {$e->getMessage()}");
        }

        return $result;
    }

    /**
     * Create (or reset) a Review shell for chunked ingest without finalizing the job.
     *
     * @param  array<string, mixed>  $meta
     */
    public function beginChunkedReview(ReviewJob $job, array $meta): Review
    {
        $existing = Review::query()->where('review_job_id', $job->id)->first();
        if ($existing) {
            Finding::query()->where('review_id', $existing->id)->delete();
            $existing->delete();
        }

        $rawStatus = strtolower((string) ($meta['status'] ?? 'passed'));
        $reviewStatus = match ($rawStatus) {
            'passed' => ReviewStatus::Passed->value,
            'passed_with_warnings' => ReviewStatus::PassedWithWarnings->value,
            'failed' => ReviewStatus::Failed->value,
            default => ReviewStatus::ReviewError->value,
        };

        return Review::query()->create([
            'review_job_id' => $job->id,
            'repository_id' => $job->repository_id,
            'branch_id' => $job->branch_id,
            'status' => $reviewStatus,
            'commit_sha' => $job->commit_sha,
            'pull_request_number' => $job->pull_request_number,
            'pull_request_title' => $meta['pull_request_title'] ?? 'Automated Review',
            'author' => $meta['author'] ?? 'Worker Agent',
            'started_at' => $job->started_at ?? now(),
            'completed_at' => null,
            'summary' => $meta['summary'] ?? 'Review in progress (chunked ingest).',
            'tools_used' => $meta['tools_used'] ?? ['gitleaks', 'semgrep', 'osv-scanner', 'ollama'],
            'coverage_percentage' => $meta['coverage_percentage'] ?? null,
            'changed_code_coverage' => $meta['changed_code_coverage'] ?? null,
            'test_summary' => $meta['test_summary'] ?? null,
            'execution_authorized' => array_key_exists('execution_authorized', $meta)
                ? (bool) $meta['execution_authorized']
                : null,
            'detected_stacks' => $meta['stacks'] ?? null,
            'change_classes' => is_array($meta['change_classes'] ?? null)
                ? array_values($meta['change_classes'])
                : null,
            'impact_analysis' => is_array($meta['impact_analysis'] ?? null)
                ? $meta['impact_analysis']
                : null,
            'tool_executions' => is_array($meta['tool_executions'] ?? null)
                ? array_values($meta['tool_executions'])
                : null,
            'github_check_run_id' => $meta['github_check_run_id'] ?? $job->github_check_run_id,
            'trello_card_id' => $job->trello_card_id,
            'trello_comment_id' => $job->trello_comment_id,
        ]);
    }

    /**
     * Persist one findings chunk against an existing Review (idempotent caller-side).
     *
     * @param  array<int, array<string, mixed>>  $rawFindings
     * @return int Number of findings inserted
     */
    public function persistFindingsChunk(ReviewJob $job, Review $review, array $rawFindings): int
    {
        if ($rawFindings === []) {
            return 0;
        }

        $allowedObjectives = $this->allowedObjectiveIds($job);
        $rows = $this->mapRawFindingsToRows($review, $rawFindings, $allowedObjectives);
        $rows = $this->findingReconciliationService->attachParents($review, $rows);

        if ($rows === []) {
            return 0;
        }

        return count($this->findingRepository->createMany($rows));
    }

    /**
     * Finalize a chunked ingest: reconcile, mark terminal, dispatch side effects.
     * Findings must already be persisted via persistFindingsChunk.
     *
     * @return array{review: Review, job: ReviewJob, raw_findings: array<int, mixed>, findings_count: int, resolved_findings: array<int, mixed>}
     */
    public function finalizeChunkedJob(int $jobId, array $meta = []): array
    {
        $result = DB::transaction(function () use ($jobId, $meta) {
            /** @var ReviewJob|null $job */
            $job = ReviewJob::query()->where('id', $jobId)->lockForUpdate()->first();

            if (! $job) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("ReviewJob #{$jobId} not found.");
            }

            if (in_array($job->status, [ReviewJobStatus::Completed, ReviewJobStatus::Failed, ReviewJobStatus::Cancelled], true)) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                    "ReviewJob #{$jobId} has already been finalized or timed out (Current status: {$job->status->value})."
                );
            }

            /** @var Review|null $review */
            $review = Review::query()->where('review_job_id', $job->id)->lockForUpdate()->first();
            if (! $review) {
                throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException(
                    "ReviewJob #{$jobId} has no Review shell; call result/begin first."
                );
            }

            $rawStatus = strtolower((string) ($meta['status'] ?? $review->status?->value ?? 'passed'));
            $reviewStatus = match ($rawStatus) {
                'passed' => ReviewStatus::Passed->value,
                'passed_with_warnings' => ReviewStatus::PassedWithWarnings->value,
                'failed' => ReviewStatus::Failed->value,
                default => ReviewStatus::ReviewError->value,
            };

            // TST-004 coverage gate (may add one finding + force failed).
            $threshold = (float) config('worker.changed_coverage_min_percent', 70);
            $changedCoverage = $meta['changed_code_coverage'] ?? $review->changed_code_coverage;
            if ($changedCoverage !== null && (float) $changedCoverage < $threshold) {
                $hasCoverageFinding = Finding::query()
                    ->where('review_id', $review->id)
                    ->where('rule_id', 'TST-004')
                    ->exists();

                if (! $hasCoverageFinding) {
                    $coverageRow = [
                        'review_id' => $review->id,
                        'file_path' => 'coverage',
                        'line_number' => 1,
                        'severity' => FindingSeverity::High->value,
                        'status' => 'open',
                        'title' => 'Changed-code coverage below threshold',
                        'evidence' => sprintf(
                            'Changed-line coverage is %.1f%% (threshold %.0f%%).',
                            (float) $changedCoverage,
                            $threshold
                        ),
                        'recommendation' => 'Add tests covering the changed lines before merging.',
                        'rule_id' => 'TST-004',
                        'fingerprint' => $this->redactionService->fingerprint(
                            'TST-004',
                            'coverage',
                            1,
                            'Changed-code coverage below threshold'
                        ),
                        'source' => 'coverage',
                        'is_blocking' => true,
                        'objective_id' => null,
                        'objective_impact' => null,
                    ];
                    $this->findingRepository->createMany([$coverageRow]);
                }

                if ($reviewStatus !== ReviewStatus::ReviewError->value) {
                    $reviewStatus = ReviewStatus::Failed->value;
                }
            }

            $resolved = $this->findingReconciliationService->reconcilePersistedFindings($review);
            $resolvedFindings = $resolved->map(fn (Finding $finding) => [
                'title' => $finding->title,
                'file_path' => $finding->file_path,
                'line_number' => $finding->line_number,
                'severity' => $finding->severity?->value ?? (string) $finding->severity,
                'fingerprint' => $finding->fingerprint,
            ])->all();

            $findingsCount = Finding::query()->where('review_id', $review->id)->count();

            $review->status = $reviewStatus;
            $review->summary = $meta['summary'] ?? $review->summary;
            $review->completed_at = Carbon::now();
            if (array_key_exists('tools_used', $meta) && $meta['tools_used'] !== null) {
                $review->tools_used = $meta['tools_used'];
            }
            $review->save();

            $finalJobStatus = ($reviewStatus === ReviewStatus::Failed->value || $reviewStatus === ReviewStatus::ReviewError->value)
                ? ReviewJobStatus::Failed
                : ReviewJobStatus::Completed;

            $job->status = $finalJobStatus;
            $job->completed_at = Carbon::now();
            $job->error_message = $meta['error_message'] ?? null;
            $job->save();

            $this->auditLogService->record(
                category: AuditCategory::Worker,
                eventType: AuditEventType::ResultSubmitted,
                action: "ReviewJob #{$job->id} finalized as {$reviewStatus} (chunked ingest)",
                severity: $reviewStatus === ReviewStatus::ReviewError->value ? AuditSeverity::Critical : AuditSeverity::Info,
                target: $review,
                reason: 'Worker chunked result completion'
            );

            // Slim payload for outbound channels — avoid reloading huge evidence blobs into memory twice.
            $rawFindings = Finding::query()
                ->where('review_id', $review->id)
                ->orderBy('id')
                ->get(['title', 'file_path', 'line_number', 'severity', 'rule_id', 'source', 'is_blocking', 'fingerprint', 'evidence', 'recommendation'])
                ->map(fn (Finding $finding) => [
                    'title' => $finding->title,
                    'file_path' => $finding->file_path,
                    'line_number' => $finding->line_number,
                    'severity' => $finding->severity?->value ?? (string) $finding->severity,
                    'rule_id' => $finding->rule_id,
                    'source' => $finding->source,
                    'is_blocking' => (bool) $finding->is_blocking,
                    'fingerprint' => $finding->fingerprint,
                    'evidence' => $finding->evidence,
                    'recommendation' => $finding->recommendation,
                ])
                ->all();

            return [
                'review' => $review->fresh(),
                'job' => $job,
                'raw_findings' => $rawFindings,
                'findings_count' => $findingsCount,
                'resolved_findings' => $resolvedFindings,
            ];
        });

        $review = $result['review'];
        $job = $result['job'];
        $rawFindings = $result['raw_findings'];
        $resolvedFindings = $result['resolved_findings'] ?? [];
        $payload = $meta;

        // Stage 2 side effects (failures must not roll back terminal status).
        try {
            $pdfPath = $this->reportPdfService->generateForReview($review);
            Report::query()->create([
                'project_id' => $job->repository?->project_id,
                'repository_id' => $job->repository_id,
                'review_id' => $review->id,
                'type' => 'review_summary',
                'format' => 'pdf',
                'file_path' => $pdfPath,
                'metadata' => [
                    'finding_count' => count($rawFindings),
                    'tools_used' => $review->tools_used,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("Post-commit PDF generation failed for Review #{$review->id}: {$e->getMessage()}");
        }

        $detailsUrl = config('app.url').'/admin/reports';

        try {
            $checkRunId = $payload['github_check_run_id'] ?? $job->github_check_run_id;
            if ($job->repository && $checkRunId) {
                $this->checkRunService->finalizeCheckRun(
                    $job->repository,
                    (int) $checkRunId,
                    $review->status->value ?? (string) $review->status,
                    $review->summary,
                    $rawFindings,
                    $detailsUrl
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Post-commit GitHub Check Run finalization failed for Review #{$review->id}: {$e->getMessage()}");
        }

        try {
            if ($job->repository && $job->pull_request_number) {
                $this->reviewCommentService->postConsolidatedReview(
                    $job->repository,
                    (int) $job->pull_request_number,
                    $job->commit_sha ?? 'HEAD',
                    $review->status->value ?? (string) $review->status,
                    $review->summary,
                    $rawFindings,
                    $detailsUrl,
                    $resolvedFindings
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Post-commit GitHub PR review posting failed for Review #{$review->id}: {$e->getMessage()}");
        }

        try {
            ReviewCompleted::dispatch($review, $rawFindings);
        } catch (\Throwable $e) {
            Log::warning("Post-commit ReviewCompleted event dispatch failed for Review #{$review->id}: {$e->getMessage()}");
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawFindings
     * @param  array<int, bool>  $allowedObjectives
     * @return array<int, array<string, mixed>>
     */
    protected function mapRawFindingsToRows(Review $review, array $rawFindings, array $allowedObjectives): array
    {
        $rows = [];

        foreach ($rawFindings as $f) {
            if (! is_array($f)) {
                continue;
            }

            $severity = $f['severity'] ?? FindingSeverity::Medium->value;
            $severityEnum = FindingSeverity::tryFrom(is_string($severity) ? $severity : (string) $severity);
            $isBlocking = array_key_exists('is_blocking', $f)
                ? (bool) $f['is_blocking']
                : ($severityEnum?->blocksMerging() ?? false);

            $objectiveId = $this->resolveObjectiveId($f, $allowedObjectives);
            if ($objectiveId !== null && ($allowedObjectives[$objectiveId] ?? false)) {
                $isBlocking = true;
            }

            $title = $f['title'] ?? 'Automated Finding';
            $lineNumber = (int) ($f['line_number'] ?? $f['line_start'] ?? 1);
            $filePath = $f['file_path'] ?? 'Unknown';
            if (is_string($filePath)) {
                $filePath = str_replace('\\', '/', $filePath);
                if (preg_match('#(?:scanner-workspaces/job_[^/]+|job_[a-f0-9]+)/(.+)#i', $filePath, $matches)) {
                    $filePath = $matches[1];
                }
                $filePath = ltrim(preg_replace('#^(\.\./)+#', '', $filePath), '/');
            }
            $ruleId = $f['rule_id'] ?? 'RULE-001';
            $evidence = $this->redactionService->redact(
                isset($f['evidence']) && is_string($f['evidence'])
                    ? $f['evidence']
                    : (isset($f['description']) && is_string($f['description']) ? $f['description'] : 'No evidence provided.')
            );
            $recommendation = $this->redactionService->redact(
                isset($f['recommendation']) && is_string($f['recommendation'])
                    ? $f['recommendation']
                    : (isset($f['remediation']) && is_string($f['remediation']) ? $f['remediation'] : null)
            );
            $objectiveImpact = $objectiveId !== null
                ? $this->redactionService->redact(
                    isset($f['objective_impact']) && is_string($f['objective_impact'])
                        ? $f['objective_impact']
                        : null
                )
                : null;

            $fingerprint = $f['fingerprint']
                ?? $this->redactionService->fingerprint($ruleId, $filePath, $lineNumber, $title);

            $rows[] = [
                'review_id' => $review->id,
                'file_path' => $filePath,
                'line_number' => $lineNumber,
                'severity' => is_string($severity) ? $severity : FindingSeverity::Medium->value,
                'status' => $f['status'] ?? 'open',
                'title' => is_string($title) ? mb_substr($title, 0, 255) : 'Automated Finding',
                'evidence' => $evidence !== '' ? $evidence : 'No evidence provided.',
                'recommendation' => $recommendation !== '' ? $recommendation : null,
                'rule_id' => $ruleId,
                'fingerprint' => $fingerprint,
                'source' => $f['source'] ?? $f['tool_name'] ?? 'static-analysis',
                'is_blocking' => $isBlocking,
                'objective_id' => $objectiveId,
                'objective_impact' => $objectiveImpact !== '' ? $objectiveImpact : null,
            ];
        }

        return $rows;
    }
}
