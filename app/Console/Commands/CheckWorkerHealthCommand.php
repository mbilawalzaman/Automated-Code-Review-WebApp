<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\WorkerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckWorkerHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'health:check-workers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect offline worker nodes, manage outage episodes, and send critical email alerts.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking review worker health status...');

        $expectedIds = (array) config('worker.expected_ids', []);
        $offlineAfterMinutes = (int) config('worker.offline_after_minutes', 15);
        $offlineThreshold = Carbon::now()->subMinutes($offlineAfterMinutes);

        // 1. Process existing worker heartbeat records
        $heartbeats = WorkerHeartbeat::query()->get();

        foreach ($heartbeats as $heartbeat) {
            $isOffline = $heartbeat->last_ping_at === null || $heartbeat->last_ping_at->lt($offlineThreshold);

            if ($isOffline) {
                $this->handleWorkerOffline($heartbeat, $offlineAfterMinutes);
            } else {
                $this->handleWorkerOnline($heartbeat);
            }
        }

        // 2. Process expected worker IDs that have never connected
        $existingWorkerIds = $heartbeats->pluck('worker_id')->all();
        foreach ($expectedIds as $expectedId) {
            if (in_array($expectedId, $existingWorkerIds, true)) {
                continue;
            }

            $missingWorker = WorkerHeartbeat::query()->create([
                'worker_id' => $expectedId,
                'last_ping_at' => null,
                'is_currently_offline' => true,
                'current_outage_episode_id' => $this->neverConnectedEpisodeId($expectedId),
            ]);

            $this->handleWorkerOffline($missingWorker, $offlineAfterMinutes);
        }

        // 3. Print worker status summary
        $allWorkers = WorkerHeartbeat::query()->get();
        if ($allWorkers->isEmpty()) {
            $this->warn('No worker nodes enrolled yet.');
        } else {
            $rows = $allWorkers->map(fn ($w) => [
                'Worker ID' => $w->worker_id,
                'Status' => $w->is_currently_offline ? 'OFFLINE' : 'ONLINE',
                'Last Heartbeat' => $w->last_ping_at ? $w->last_ping_at->diffForHumans() : 'Never',
                'Hostname' => $w->hostname ?? 'N/A',
            ])->toArray();

            $this->table(['Worker ID', 'Status', 'Last Heartbeat', 'Hostname'], $rows);
        }

        $this->info('Worker health check completed.');

        return self::SUCCESS;
    }

    protected function handleWorkerOffline(WorkerHeartbeat $worker, int $offlineAfterMinutes): void
    {
        if (! $worker->is_currently_offline) {
            $worker->is_currently_offline = true;
            $worker->current_outage_episode_id = 'outage_' . $worker->worker_id . '_' . time();
            $worker->save();

            Log::warning("Worker node '{$worker->worker_id}' declared offline. Episode: {$worker->current_outage_episode_id}");
        }

        // A worker that has never connected gets one reminder per day rather than
        // a single alert forever, since there is no recovery event to reset it.
        if ($worker->last_ping_at === null) {
            $expectedEpisodeId = $this->neverConnectedEpisodeId($worker->worker_id);

            if ($worker->current_outage_episode_id !== $expectedEpisodeId) {
                $worker->current_outage_episode_id = $expectedEpisodeId;
                $worker->save();
            }
        }

        $episodeId = $worker->current_outage_episode_id ?: ('outage_' . $worker->worker_id . '_' . time());
        $idempotencyKey = "worker_offline:{$episodeId}";

        // Check if an alert was already successfully dispatched for this outage episode using json metadata
        $alreadyAlerted = Notification::query()
            ->where('metadata->idempotency_key', $idempotencyKey)
            ->where('status', NotificationStatus::Sent->value)
            ->exists();

        if ($alreadyAlerted) {
            return;
        }

        // Cooldown check (don't retry failed email sends too aggressively)
        $retryCooldown = (int) config('worker.outage_alert_retry_minutes', 15);
        if ($worker->last_outage_alert_at && $worker->last_outage_alert_at->gte(Carbon::now()->subMinutes($retryCooldown))) {
            return;
        }

        $recipients = $this->resolveRecipients();

        if ($recipients === []) {
            Log::info("Worker outage alert for '{$worker->worker_id}' suppressed: CRITICAL_ALERT_EMAILS is empty.");

            return;
        }

        $subject = "[CRITICAL] Review Worker Offline: {$worker->worker_id}";
        $body = "CRITICAL ALERT: Review worker node '{$worker->worker_id}' has not sent a heartbeat for over {$offlineAfterMinutes} minutes.\n" .
            'Last Heartbeat: ' . ($worker->last_ping_at ? $worker->last_ping_at->toIso8601String() : 'Never (worker has not enrolled yet)') . "\n" .
            "Outage Episode: {$episodeId}\n\n" .
            'Pending jobs will remain queued until worker heartbeat resumes.';

        try {
            Mail::raw($body, function ($message) use ($recipients, $subject) {
                $fromAddress = config('mail.from.address') ?: 'system@codereviewapp.online';
                $fromName = config('mail.from.name') ?: 'QA Platform';
                $message->to($recipients)->subject($subject)->from($fromAddress, $fromName);
            });

            // Update state ONLY AFTER successful mail delivery
            $worker->last_outage_alert_at = Carbon::now();
            $worker->save();

            Notification::query()->create([
                'channel' => 'mail',
                'type' => 'worker_offline_alert',
                'subject' => $subject,
                'body' => $body,
                'status' => NotificationStatus::Sent->value,
                'sent_at' => now(),
                'metadata' => [
                    'idempotency_key' => $idempotencyKey,
                    'worker_id' => $worker->worker_id,
                    'episode_id' => $episodeId,
                    'last_ping_at' => $worker->last_ping_at?->toIso8601String(),
                    'recipients' => $recipients,
                ],
            ]);

            $this->info("Worker outage alert email successfully sent for '{$worker->worker_id}'.");
        } catch (\Throwable $e) {
            Log::error("Failed to send worker outage alert email for '{$worker->worker_id}': {$e->getMessage()}");

            $worker->last_outage_alert_at = Carbon::now();
            $worker->save();

            Notification::query()->create([
                'channel' => 'mail',
                'type' => 'worker_offline_alert',
                'subject' => $subject,
                'body' => $body,
                'status' => NotificationStatus::Failed->value,
                'metadata' => [
                    'idempotency_key' => $idempotencyKey,
                    'worker_id' => $worker->worker_id,
                    'episode_id' => $episodeId,
                    'error' => $e->getMessage(),
                    'recipients' => $recipients,
                ],
            ]);
        }
    }

    protected function handleWorkerOnline(WorkerHeartbeat $worker): void
    {
        if ($worker->is_currently_offline) {
            Log::info("Worker node '{$worker->worker_id}' has recovered and resumed heartbeats. Resolving outage episode.");

            $worker->is_currently_offline = false;
            $worker->current_outage_episode_id = null;
            $worker->last_outage_alert_at = null;
            $worker->save();
        }
    }

    /**
     * Stable per-day episode ID for an expected worker that has never enrolled.
     */
    protected function neverConnectedEpisodeId(string $workerId): string
    {
        return 'outage_' . $workerId . '_never_connected_' . Carbon::now()->format('Y_m_d');
    }

    /**
     * Recipients come from the same CRITICAL_ALERT_EMAILS list used by NOT-001
     * critical finding emails (config/services.php).
     *
     * @return array<int, string>
     */
    protected function resolveRecipients(): array
    {
        $raw = config('services.alerts.critical_emails', '');
        $raw = is_array($raw) ? implode(',', $raw) : (string) $raw;

        return collect(preg_split('/[,\s;]+/', $raw) ?: [])
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
