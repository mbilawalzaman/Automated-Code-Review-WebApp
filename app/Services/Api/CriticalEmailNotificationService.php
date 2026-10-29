<?php

namespace App\Services\Api;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\FindingSeverity;
use App\Enums\NotificationStatus;
use App\Mail\CriticalReviewAlertMail;
use App\Models\Notification;
use App\Models\Review;
use App\Services\Admin\AuditLogService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CriticalEmailNotificationService
{
    public const TYPE = 'critical_review_alert';

    public function __construct(
        protected EmailRedactionService $redactionService,
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * Send a synchronous critical alert email when applicable (NOT-001 / NOT-002).
     * Does not use Laravel queue — safe on shared hosting without queue:work.
     *
     * @param  array<int, mixed>  $rawFindings
     */
    public function sendIfCritical(Review $review, array $rawFindings = []): void
    {
        $review->loadMissing(['repository', 'findings']);

        if (! $this->isEmailEnabledForRepository($review)) {
            return;
        }

        $criticalFindings = $this->collectCriticalFindings($review, $rawFindings);

        if ($criticalFindings === []) {
            return;
        }

        if ($this->alreadySentForReview($review)) {
            Log::info('Critical email skipped (already sent)', ['review_id' => $review->id]);

            return;
        }

        $recipients = $this->resolveRecipients();

        if ($recipients === []) {
            Log::warning('Critical email skipped: CRITICAL_ALERT_EMAILS is empty', [
                'review_id' => $review->id,
            ]);

            return;
        }

        $dashboardUrl = rtrim((string) config('app.url'), '/').'/admin/findings';
        $mailable = new CriticalReviewAlertMail($review, $criticalFindings, $dashboardUrl);

        try {
            Mail::to($recipients)->send($mailable);

            Notification::query()->create([
                'user_id' => null,
                'review_id' => $review->id,
                'channel' => 'email',
                'type' => self::TYPE,
                'subject' => $mailable->envelope()->subject,
                'body' => $this->buildStoredBody($review, $criticalFindings),
                'status' => NotificationStatus::Sent,
                'sent_at' => now(),
                'metadata' => [
                    'recipients' => $recipients,
                    'critical_count' => count($criticalFindings),
                    'idempotency_key' => $this->idempotencyKey($review),
                ],
            ]);

            $this->auditLogService->record(
                category: AuditCategory::Review,
                eventType: AuditEventType::ResultSubmitted,
                action: "Critical review alert email sent for Review #{$review->id}",
                severity: AuditSeverity::Critical,
                target: $review,
                afterState: [
                    'recipients' => $recipients,
                    'critical_count' => count($criticalFindings),
                ],
                reason: 'NOT-001 critical email alert'
            );
        } catch (Throwable $e) {
            Log::error('Critical email failed: '.$e->getMessage(), [
                'review_id' => $review->id,
            ]);

            Notification::query()->create([
                'user_id' => null,
                'review_id' => $review->id,
                'channel' => 'email',
                'type' => self::TYPE,
                'subject' => $mailable->envelope()->subject,
                'body' => $e->getMessage(),
                'status' => NotificationStatus::Failed,
                'sent_at' => null,
                'metadata' => [
                    'recipients' => $recipients,
                    'error' => $e->getMessage(),
                    'idempotency_key' => $this->idempotencyKey($review),
                ],
            ]);
        }
    }

    protected function isEmailEnabledForRepository(Review $review): bool
    {
        $settings = $review->repository?->notification_settings ?? [];

        if (array_key_exists('email_on_critical', $settings)) {
            return (bool) $settings['email_on_critical'];
        }

        if (array_key_exists('critical_email', $settings)) {
            return (bool) $settings['critical_email'];
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $rawFindings
     * @return array<int, array<string, mixed>>
     */
    protected function collectCriticalFindings(Review $review, array $rawFindings): array
    {
        $fromDb = $review->findings
            ->filter(fn ($finding) => $finding->severity === FindingSeverity::Critical)
            ->map(fn ($finding) => [
                'title' => $this->redactionService->redact($finding->title),
                'severity' => FindingSeverity::Critical->value,
                'file_path' => $finding->file_path ?? 'Unknown',
                'line_number' => $finding->line_number,
                'evidence' => $this->redactionService->safeEvidenceSnippet($finding->evidence),
            ])
            ->values()
            ->all();

        if ($fromDb !== []) {
            return $fromDb;
        }

        $fromPayload = [];
        foreach ($rawFindings as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $severity = strtolower((string) ($finding['severity'] ?? ''));
            if ($severity !== FindingSeverity::Critical->value) {
                continue;
            }
            $fromPayload[] = [
                'title' => $this->redactionService->redact((string) ($finding['title'] ?? 'Critical finding')),
                'severity' => FindingSeverity::Critical->value,
                'file_path' => (string) ($finding['file_path'] ?? 'Unknown'),
                'line_number' => $finding['line_number'] ?? $finding['line_start'] ?? null,
                'evidence' => $this->redactionService->safeEvidenceSnippet(
                    $finding['evidence'] ?? $finding['description'] ?? null
                ),
            ];
        }

        return $fromPayload;
    }

    protected function alreadySentForReview(Review $review): bool
    {
        return Notification::query()
            ->where('review_id', $review->id)
            ->where('type', self::TYPE)
            ->where('status', NotificationStatus::Sent->value)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    protected function resolveRecipients(): array
    {
        $raw = (string) config('services.alerts.critical_emails', '');

        return collect(preg_split('/[,\s;]+/', $raw) ?: [])
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $criticalFindings
     */
    protected function buildStoredBody(Review $review, array $criticalFindings): string
    {
        $repo = $review->repository?->full_name ?? 'N/A';
        $lines = [
            "Critical review alert for {$repo}",
            'Commit: '.($review->commit_sha ?? 'N/A'),
            'Critical findings: '.count($criticalFindings),
        ];

        foreach ($criticalFindings as $finding) {
            $lines[] = sprintf(
                '- %s @ %s%s',
                $finding['title'],
                $finding['file_path'],
                isset($finding['line_number']) ? ':'.$finding['line_number'] : ''
            );
        }

        return $this->redactionService->redact(implode("\n", $lines));
    }

    protected function idempotencyKey(Review $review): string
    {
        return sha1('critical_review_alert:'.$review->id);
    }
}
