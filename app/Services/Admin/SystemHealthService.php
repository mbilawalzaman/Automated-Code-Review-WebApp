<?php

namespace App\Services\Admin;

use App\Enums\ReviewJobStatus;
use App\Models\ReviewJob;
use App\Models\WorkerHeartbeat;
use App\Services\BackupService;
use App\Services\Integrations\LiveIntegrationHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SystemHealthService
{
    public function __construct(
        protected BackupService $backupService
    ) {}

    /**
     * Compute full system health metrics (OPS-001).
     *
     * @return array<string, mixed>
     */
    public function getHealthMetrics(bool $includeSensitiveData = false): array
    {
        // 1. Worker Liveness & Expected Workers
        $expectedIds = (array) config('worker.expected_ids', []);
        $heartbeats = WorkerHeartbeat::query()->get()->keyBy('worker_id');
        $workerHealthList = [];

        foreach ($expectedIds as $workerId) {
            /** @var WorkerHeartbeat|null $record */
            $record = $heartbeats->get($workerId);

            if (! $record) {
                $workerHealthList[] = [
                    'worker_id' => $workerId,
                    'status' => 'Expected (Never Connected)',
                    'status_class' => 'bg-amber-100 text-amber-800 border-amber-200',
                    'last_ping_formatted' => 'Never',
                    'is_online' => false,
                ];
            } else {
                $isOnline = $record->is_online;

                if ($record->last_ping_at === null) {
                    $statusLabel = 'Expected (Never Connected)';
                    $statusClass = 'bg-amber-100 text-amber-800 border-amber-200';
                } else {
                    $statusLabel = $isOnline ? 'Online & Polling' : 'Offline';
                    $statusClass = $isOnline ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200';
                }

                $entry = [
                    'worker_id' => $record->worker_id,
                    'status' => $statusLabel,
                    'status_class' => $statusClass,
                    'last_ping_formatted' => $record->last_ping_at ? $record->last_ping_at->diffForHumans() : 'Never',
                    'is_online' => $isOnline,
                ];

                if ($includeSensitiveData) {
                    $entry['hostname'] = $record->hostname;
                    $entry['os_info'] = $record->os_info;
                    $entry['python_version'] = $record->python_version;
                }

                $workerHealthList[] = $entry;
            }
        }

        // Add any active workers not in expected list
        foreach ($heartbeats as $wId => $record) {
            if (! in_array($wId, $expectedIds, true)) {
                $isOnline = $record->is_online;
                $entry = [
                    'worker_id' => $record->worker_id,
                    'status' => $isOnline ? 'Online & Polling' : 'Offline',
                    'status_class' => $isOnline ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200',
                    'last_ping_formatted' => $record->last_ping_at ? $record->last_ping_at->diffForHumans() : 'Never',
                    'is_online' => $isOnline,
                ];

                if ($includeSensitiveData) {
                    $entry['hostname'] = $record->hostname;
                    $entry['os_info'] = $record->os_info;
                    $entry['python_version'] = $record->python_version;
                }

                $workerHealthList[] = $entry;
            }
        }

        // 2. Review Queue Depth & Stuck Jobs
        $pendingCount = ReviewJob::query()->where('status', ReviewJobStatus::Pending->value)->count();
        $processingCount = ReviewJob::query()->whereIn('status', [ReviewJobStatus::Processing->value, 'running'])->count();

        $oldestPending = ReviewJob::query()
            ->where('status', ReviewJobStatus::Pending->value)
            ->orderBy('created_at', 'asc')
            ->first();

        $stuckAfterMinutes = (int) config('worker.stuck_job_after_minutes', 30);
        $stuckThreshold = Carbon::now()->subMinutes($stuckAfterMinutes);
        $stuckJobsCount = ReviewJob::query()
            ->whereIn('status', [ReviewJobStatus::Processing->value, 'running'])
            ->where('started_at', '<=', $stuckThreshold)
            ->count();

        // 3. Database, Storage, Scheduler Freshness & Failed Jobs
        $dbHealthy = false;
        try {
            DB::select('SELECT 1');
            $dbHealthy = true;
        } catch (\Throwable $e) {
            $dbHealthy = false;
        }

        $storageWritable = is_writable(storage_path('app'));

        $lastSeenScheduler = $this->resolveCachedTimestamp(Cache::get('scheduler:last_seen_at'));
        $schedulerStaleAfter = (int) config('worker.scheduler_stale_after_minutes', 3);
        $schedulerHealthy = $lastSeenScheduler !== null
            && $lastSeenScheduler->gte(Carbon::now()->subMinutes($schedulerStaleAfter));

        $schedulerLabel = match (true) {
            $schedulerHealthy => 'Healthy & Running',
            $lastSeenScheduler === null => 'No Signal Recorded Yet',
            default => 'Stale / Crontab Missing',
        };

        $failedJobsCount = 0;
        try {
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $failedJobsCount = DB::table('failed_jobs')->count();
            }
        } catch (\Throwable $e) {
            // Ignore if table missing
        }

        // 4. Backup System Status
        $backupStats = $this->backupService->getSummaryStats();

        // 5. Integration Status (live connectivity probes)
        $integrations = app(LiveIntegrationHealthService::class)->probeAll();

        // 6. Cancellable jobs (pending / processing / stuck)
        $cancellableJobs = ReviewJob::query()
            ->with(['repository:id,full_name,name'])
            ->whereIn('status', [
                ReviewJobStatus::Pending->value,
                ReviewJobStatus::Processing->value,
                ReviewJobStatus::Running->value,
            ])
            ->orderBy('created_at')
            ->limit(25)
            ->get()
            ->map(fn (ReviewJob $job) => [
                'id' => $job->id,
                'status' => $job->status->value,
                'repository' => $job->repository?->full_name ?? $job->repository?->name ?? '—',
                'commit_sha' => $job->commit_sha ? substr((string) $job->commit_sha, 0, 7) : '—',
                'started_at' => $job->started_at?->diffForHumans() ?? 'Not started',
                'created_at' => $job->created_at?->diffForHumans() ?? '—',
                'is_stuck' => $job->started_at !== null && $job->started_at->lte($stuckThreshold),
            ])
            ->all();

        return [
            'overall_status' => ($dbHealthy && $storageWritable && $schedulerHealthy) ? 'Healthy' : 'Degraded',
            'workers' => $workerHealthList,
            'queue' => [
                'pending' => $pendingCount,
                'processing' => $processingCount,
                'oldest_pending_age' => $oldestPending ? $oldestPending->created_at->diffForHumans() : 'None',
                'stuck_jobs' => $stuckJobsCount,
                'jobs' => $cancellableJobs,
            ],
            'system' => [
                'database' => $dbHealthy ? 'Healthy' : 'Unhealthy',
                'storage' => $storageWritable ? 'Writable' : 'Read-Only / Unwritable',
                'scheduler' => $schedulerLabel,
                'scheduler_last_seen' => $lastSeenScheduler?->diffForHumans() ?? 'Never',
                'failed_jobs_count' => $failedJobsCount,
            ],
            'backups' => $backupStats,
            'integrations' => $integrations,
        ];
    }

    /**
     * Normalize a cache timestamp that may be a string, Carbon, DateTime, or a
     * legacy __PHP_Incomplete_Class left by caching Carbon objects directly.
     */
    protected function resolveCachedTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
