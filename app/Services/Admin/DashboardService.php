<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\FindingSeverity;
use App\Enums\ReviewJobStatus;
use App\Models\AuditLog;
use App\Models\Finding;
use App\Models\Review;
use App\Models\ReviewJob;
use App\Repositories\Contracts\FindingRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\RepositoryRepositoryInterface;
use App\Models\WorkerHeartbeat;
use App\Repositories\Contracts\ReviewRepositoryInterface;
use Illuminate\Support\Carbon;

class DashboardService
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly RepositoryRepositoryInterface $repositoryRepository,
        private readonly ReviewRepositoryInterface $reviewRepository,
        private readonly FindingRepositoryInterface $findingRepository,
    ) {
    }

    /**
     * Get rich executive summary data for main Admin Dashboard.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $today = Carbon::today();
        $twentyFourHoursAgo = now()->subHours(24);
        $fifteenMinutesAgo = now()->subMinutes(15);

        // Core Metrics
        $projectsCount = $this->projectRepository->count();
        $reposCount = $this->repositoryRepository->count();
        $reviewsCount = $this->reviewRepository->count();
        $findingsCount = $this->findingRepository->count();

        // 1. Security & Audit Group
        $auditEventsToday = AuditLog::query()->where('created_at', '>=', $today)->count();

        $failedLogins24h = AuditLog::query()
            ->where('event_type', AuditEventType::LoginFailed->value)
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->count();

        $criticalSecurityAlerts = AuditLog::query()
            ->where('severity', AuditSeverity::Critical->value)
            ->count();

        // 2. Worker Engine Group (Data-Driven Worker Health via WorkerHeartbeat)
        $hasActiveHeartbeat = WorkerHeartbeat::query()
            ->where('last_ping_at', '>=', $fifteenMinutesAgo)
            ->exists();

        // Fallback to recent AuditLog if heartbeats haven't been seeded yet
        if (!$hasActiveHeartbeat) {
            $hasActiveHeartbeat = AuditLog::query()
                ->where('category', AuditCategory::Worker->value)
                ->where('created_at', '>=', $fifteenMinutesAgo)
                ->exists();
        }

        $workerStatusLabel = $hasActiveHeartbeat ? 'Online & Polling' : 'Offline';
        $workerStatusClass = $hasActiveHeartbeat ? 'bg-green-100 text-green-800 border-green-200' : 'bg-gray-100 text-gray-700 border-gray-200';

        $pendingJobsCount = ReviewJob::query()
            ->where('status', ReviewJobStatus::Pending->value)
            ->count();

        $jobsProcessedToday = ReviewJob::query()
            ->where('status', ReviewJobStatus::Completed->value)
            ->where('updated_at', '>=', $today)
            ->count();

        // 3. Code Review & Quality Group
        $reviewsPending = ReviewJob::query()
            ->whereIn('status', [ReviewJobStatus::Pending->value, ReviewJobStatus::Processing->value])
            ->count();

        $reviewsCompletedToday = Review::query()
            ->where('completed_at', '>=', $today)
            ->count();

        $criticalHighFindings = Finding::query()
            ->whereIn('severity', [FindingSeverity::Critical->value, FindingSeverity::High->value])
            ->where('status', 'open')
            ->count();

        return [
            'core' => [
                'projects' => $projectsCount,
                'repositories' => $reposCount,
                'reviews' => $reviewsCount,
                'findings' => $findingsCount,
            ],
            'security_audit' => [
                'audit_events_today' => $auditEventsToday,
                'failed_logins_24h' => $failedLogins24h,
                'critical_alerts' => $criticalSecurityAlerts,
            ],
            'worker_engine' => [
                'status_label' => $workerStatusLabel,
                'status_class' => $workerStatusClass,
                'is_online' => $hasActiveHeartbeat,
                'pending_jobs' => $pendingJobsCount,
                'jobs_processed_today' => $jobsProcessedToday,
            ],
            'code_review' => [
                'reviews_pending' => $reviewsPending,
                'reviews_completed_today' => $reviewsCompletedToday,
                'critical_high_findings' => $criticalHighFindings,
            ],
        ];
    }
}
