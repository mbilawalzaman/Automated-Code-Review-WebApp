<?php

namespace App\Console\Commands;

use App\Enums\ReviewJobStatus;
use App\Models\ReviewJob;
use App\Services\Api\ReviewJobFinalizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SweepStuckJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jobs:sweep-stuck';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sweep review jobs stuck in processing/running state past the configured timeout and finalize them as terminal timeouts.';

    public function __construct(
        protected ReviewJobFinalizationService $finalizationService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for stuck review jobs...');

        $stuckAfterMinutes = (int) config('worker.stuck_job_after_minutes', 30);
        $stuckThreshold = Carbon::now()->subMinutes($stuckAfterMinutes);

        $stuckJobs = ReviewJob::query()
            ->whereIn('status', [ReviewJobStatus::Processing->value, 'running'])
            ->where('started_at', '<=', $stuckThreshold)
            ->get();

        if ($stuckJobs->isEmpty()) {
            $this->info('No stuck review jobs found.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$stuckJobs->count()} stuck review job(s). Finalizing as terminal timeout...");

        $sweptCount = 0;
        foreach ($stuckJobs as $job) {
            try {
                $reason = "Job timed out after {$stuckAfterMinutes} minutes in processing state (started at {$job->started_at?->toIso8601String()}).";
                $payload = is_array($job->payload) ? $job->payload : [];
                if (isset($payload['result_ingest']) && is_array($payload['result_ingest'])) {
                    $reason = "result_submission_timeout: {$reason}";
                }
                $this->finalizationService->finalizeTimeout($job, $reason);
                $sweptCount++;
                $this->info("Swept stuck ReviewJob #{$job->id}.");
            } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
                $this->info("ReviewJob #{$job->id} was already finalized by worker; skipping.");
            } catch (\Throwable $e) {
                Log::error("Failed to sweep stuck ReviewJob #{$job->id}: {$e->getMessage()}");
            }
        }

        $this->info("Stuck job sweeping completed. Swept {$sweptCount} job(s).");

        return Command::SUCCESS;
    }
}
