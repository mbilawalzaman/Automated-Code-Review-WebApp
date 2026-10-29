<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\ReviewJobStatus;
use App\Models\ReviewJob;
use App\Models\User;
use App\Services\Api\GitHubCheckRunService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReviewJobCancellationService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected GitHubCheckRunService $gitHubCheckRunService,
    ) {}

    public function cancel(ReviewJob $job, User $actor, ?string $reason = null): ReviewJob
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            $reason = 'Cancelled by administrator';
        }

        $cancelled = DB::transaction(function () use ($job, $actor, $reason) {
            /** @var ReviewJob $locked */
            $locked = ReviewJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            $cancellable = [
                ReviewJobStatus::Pending,
                ReviewJobStatus::Processing,
                ReviewJobStatus::Running,
            ];

            if (! in_array($locked->status, $cancellable, true)) {
                throw new InvalidArgumentException(
                    "Review job #{$locked->id} cannot be cancelled from status {$locked->status->value}."
                );
            }

            $locked->forceFill([
                'status' => ReviewJobStatus::Cancelled,
                'completed_at' => now(),
                'error_message' => $reason,
            ])->save();

            $this->auditLogService->record(
                category: AuditCategory::Worker,
                eventType: AuditEventType::Updated,
                action: 'Review job cancelled',
                severity: AuditSeverity::Warning,
                target: $locked,
                reason: $reason,
                afterState: [
                    'status' => ReviewJobStatus::Cancelled->value,
                    'repository_id' => $locked->repository_id,
                    'commit_sha' => $locked->commit_sha,
                ],
                actor: $actor,
            );

            return $locked->fresh(['repository']);
        });

        $this->finalizeGitHubCheck($cancelled, $reason);

        return $cancelled;
    }

    protected function finalizeGitHubCheck(ReviewJob $job, string $reason): void
    {
        if (! $job->github_check_run_id || ! $job->repository) {
            return;
        }

        try {
            $this->gitHubCheckRunService->cancelCheckRun(
                $job->repository,
                (string) $job->github_check_run_id,
                $reason,
            );
        } catch (\Throwable) {
            // Cancellation must succeed even if GitHub is unreachable.
        }
    }
}
