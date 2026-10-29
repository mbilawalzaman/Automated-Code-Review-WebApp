<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\ReviewJobStatus;
use App\Models\AuditLog;
use App\Models\ReviewJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditAnalyticsService
{
    /**
     * Compute comprehensive metrics for Phase 8.1 Audit Dashboard.
     *
     * @return array<string, mixed>
     */
    public function getAnalyticsData(): array
    {
        $now = now();
        $twentyFourHoursAgo = $now->copy()->subHours(24);

        // 1. KPI Cards Metrics
        $totalEvents = AuditLog::query()->count();
        $eventsLast24h = AuditLog::query()->where('created_at', '>=', $twentyFourHoursAgo)->count();

        $failedLogins24h = AuditLog::query()
            ->where('event_type', AuditEventType::LoginFailed->value)
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->count();

        $securityAlerts24h = AuditLog::query()
            ->where('category', AuditCategory::Security->value)
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->count();

        $reviewOverridesTotal = AuditLog::query()
            ->where('event_type', AuditEventType::Override->value)
            ->count();

        $workerJobsClaimed24h = AuditLog::query()
            ->where('event_type', AuditEventType::JobClaimed->value)
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->count();

        // 2. Data-Driven Worker System Health (Last 15 minutes polling check)
        $fifteenMinutesAgo = $now->copy()->subMinutes(15);
        $recentWorkerLog = AuditLog::query()
            ->where('category', AuditCategory::Worker->value)
            ->where('created_at', '>=', $fifteenMinutesAgo)
            ->latest()
            ->first();

        $activeWorkersCount = AuditLog::query()
            ->where('category', AuditCategory::Worker->value)
            ->where('created_at', '>=', $fifteenMinutesAgo)
            ->distinct('user_agent')
            ->count('user_agent');

        $pendingJobsCount = ReviewJob::query()
            ->where('status', ReviewJobStatus::Pending->value)
            ->count();

        $processingJobsCount = ReviewJob::query()
            ->where('status', ReviewJobStatus::Processing->value)
            ->count();

        // Real Average Job Processing Time (in seconds)
        $avgProcessingTimeSeconds = ReviewJob::query()
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->where('status', ReviewJobStatus::Completed->value)
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_duration')
            ->value('avg_duration');

        $avgProcessingTimeFormatted = $avgProcessingTimeSeconds ? round((float) $avgProcessingTimeSeconds, 1) . 's' : 'N/A';

        $workerStatus = [
            'is_online' => $recentWorkerLog !== null,
            'status_label' => $recentWorkerLog !== null ? 'Online & Polling' : 'Offline / Waiting for Connection',
            'status_badge' => $recentWorkerLog !== null ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700',
            'active_workers' => $activeWorkersCount,
            'last_polled_at' => $recentWorkerLog ? $recentWorkerLog->created_at->diffForHumans() : 'Never',
            'pending_queue' => $pendingJobsCount,
            'processing_queue' => $processingJobsCount,
            'avg_duration' => $avgProcessingTimeFormatted,
        ];

        // 3. 30-Day Daily Audit Activity Trend (Line Chart)
        $thirtyDaysAgo = $now->copy()->subDays(29)->startOfDay();
        $rawDailyTrends = AuditLog::query()
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->select(DB::raw('DATE(created_at) as date_val'), DB::raw('COUNT(*) as total'))
            ->groupBy('date_val')
            ->orderBy('date_val', 'asc')
            ->pluck('total', 'date_val')
            ->toArray();

        $dailyDates = [];
        $dailyCounts = [];
        for ($i = 29; $i >= 0; $i--) {
            $dateKey = $now->copy()->subDays($i)->format('Y-m-d');
            $displayDate = $now->copy()->subDays($i)->format('M d');
            $dailyDates[] = $displayDate;
            $dailyCounts[] = $rawDailyTrends[$dateKey] ?? 0;
        }

        // 4. Category Distribution Breakdown (Donut Chart)
        $categoryCounts = AuditLog::query()
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        $categoryLabels = [];
        $categorySeries = [];
        foreach (AuditCategory::cases() as $cat) {
            $val = $cat->value;
            $count = $categoryCounts[$val] ?? 0;
            if ($count > 0 || count($categoryCounts) === 0) {
                $categoryLabels[] = $cat->label();
                $categorySeries[] = $count;
            }
        }

        // 5. Severity Breakdown (Bar Chart)
        $severityCounts = AuditLog::query()
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $severityLabels = ['Info', 'Warning', 'Critical'];
        $severitySeries = [
            $severityCounts[AuditSeverity::Info->value] ?? 0,
            $severityCounts[AuditSeverity::Warning->value] ?? 0,
            $severityCounts[AuditSeverity::Critical->value] ?? 0,
        ];

        // 6. Recent Security Activity Stream (Last 10 security/auth/rbac events)
        $recentSecurityEvents = AuditLog::query()
            ->with('user')
            ->whereIn('category', [
                AuditCategory::Auth->value,
                AuditCategory::Security->value,
                AuditCategory::Rbac->value,
            ])
            ->latest()
            ->limit(10)
            ->get();

        return [
            'kpi' => [
                'total_events' => $totalEvents,
                'events_24h' => $eventsLast24h,
                'failed_logins_24h' => $failedLogins24h,
                'security_alerts_24h' => $securityAlerts24h,
                'review_overrides' => $reviewOverridesTotal,
                'worker_jobs_24h' => $workerJobsClaimed24h,
            ],
            'worker_health' => $workerStatus,
            'charts' => [
                'daily_trend' => [
                    'dates' => $dailyDates,
                    'counts' => $dailyCounts,
                ],
                'categories' => [
                    'labels' => $categoryLabels,
                    'series' => $categorySeries,
                ],
                'severities' => [
                    'labels' => $severityLabels,
                    'series' => $severitySeries,
                ],
            ],
            'recent_security_events' => $recentSecurityEvents,
        ];
    }
}
