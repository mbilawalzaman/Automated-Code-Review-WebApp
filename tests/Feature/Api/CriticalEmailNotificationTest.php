<?php

namespace Tests\Feature\Api;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\NotificationStatus;
use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;
use App\Mail\CriticalReviewAlertMail;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CriticalEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Repository $repository;

    protected string $workerToken = 'test-worker-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.worker.token' => $this->workerToken,
            'services.alerts.critical_emails' => 'pm@qaplatform.test,security@qaplatform.test',
            'mail.default' => 'array',
        ]);

        $project = Project::factory()->create();
        $this->repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'acme/payments-api',
            'status' => RepositoryStatus::Active,
            'notification_settings' => ['email_on_critical' => true],
        ]);
    }

    public function test_critical_finding_sends_redacted_email_and_persists_notification(): void
    {
        Mail::fake();

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_pull_request',
            'commit_sha' => 'deadbeef1234567890abcdef1234567890abcdef',
            'pull_request_number' => 42,
            'idempotency_key' => 'email-critical-'.uniqid(),
            'status' => ReviewJobStatus::Processing->value,
        ]);

        $response = $this->postJson(
            "/api/v1/worker/jobs/{$job->id}/result",
            [
                'status' => ReviewStatus::Failed->value,
                'summary' => 'Critical secret detected.',
                'pull_request_title' => 'Fix auth',
                'author' => 'dev',
                'tools_used' => ['gitleaks'],
                'findings' => [
                    [
                        'severity' => FindingSeverity::Critical->value,
                        'status' => FindingStatus::Open->value,
                        'file_path' => 'config/services.php',
                        'line_number' => 10,
                        'title' => 'Hardcoded API key',
                        'evidence' => 'api_key=super-secret-value-should-not-leak Match: sk_live_abc123',
                        'recommendation' => 'Rotate and remove secret',
                        'is_blocking' => true,
                    ],
                ],
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertOk();

        Mail::assertSent(CriticalReviewAlertMail::class, function (CriticalReviewAlertMail $mail) {
            $this->assertNotEmpty($mail->criticalFindings);
            $evidence = $mail->criticalFindings[0]['evidence'] ?? '';
            $this->assertStringNotContainsString('super-secret-value-should-not-leak', $evidence);
            $this->assertStringNotContainsString('sk_live_abc123', $evidence);

            return $mail->hasTo('pm@qaplatform.test')
                && $mail->hasTo('security@qaplatform.test');
        });

        $this->assertDatabaseHas('notifications', [
            'channel' => 'email',
            'type' => 'critical_review_alert',
            'status' => NotificationStatus::Sent->value,
        ]);
    }

    public function test_medium_only_findings_do_not_send_email(): void
    {
        Mail::fake();

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_push',
            'commit_sha' => 'mediumonly1234567890abcdef1234567890abcd',
            'idempotency_key' => 'email-medium-'.uniqid(),
            'status' => ReviewJobStatus::Processing->value,
        ]);

        $this->postJson(
            "/api/v1/worker/jobs/{$job->id}/result",
            [
                'status' => ReviewStatus::PassedWithWarnings->value,
                'summary' => 'Warnings only.',
                'findings' => [
                    [
                        'severity' => FindingSeverity::Medium->value,
                        'title' => 'Style issue',
                        'file_path' => 'app/Foo.php',
                        'line_number' => 1,
                        'evidence' => 'complexity high',
                    ],
                ],
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        )->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(0, Notification::query()->where('type', 'critical_review_alert')->count());
    }

    public function test_duplicate_review_completed_does_not_resend_email(): void
    {
        Mail::fake();

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_pull_request',
            'commit_sha' => 'dupemail1234567890abcdef1234567890abcdef',
            'idempotency_key' => 'email-dup-'.uniqid(),
            'status' => ReviewJobStatus::Processing->value,
        ]);

        $payload = [
            'status' => ReviewStatus::Failed->value,
            'summary' => 'Critical.',
            'findings' => [
                [
                    'severity' => FindingSeverity::Critical->value,
                    'title' => 'Critical issue',
                    'file_path' => 'app/Secret.php',
                    'line_number' => 2,
                    'evidence' => 'problem',
                    'is_blocking' => true,
                ],
            ],
        ];

        $this->postJson(
            "/api/v1/worker/jobs/{$job->id}/result",
            $payload,
            ['Authorization' => 'Bearer '.$this->workerToken]
        )->assertOk();

        $review = \App\Models\Review::query()->where('review_job_id', $job->id)->first();
        $this->assertNotNull($review);

        // Simulate a second ReviewCompleted for the same review (retry path)
        event(new \App\Events\ReviewCompleted($review, $payload['findings']));

        Mail::assertSent(CriticalReviewAlertMail::class, 1);
        $this->assertSame(
            1,
            Notification::query()
                ->where('review_id', $review->id)
                ->where('type', 'critical_review_alert')
                ->where('status', NotificationStatus::Sent->value)
                ->count()
        );
    }

    public function test_email_skipped_when_repository_disables_critical_alerts(): void
    {
        Mail::fake();

        $this->repository->update([
            'notification_settings' => ['email_on_critical' => false],
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_pull_request',
            'commit_sha' => 'disabledemail1234567890abcdef12345678',
            'idempotency_key' => 'email-disabled-'.uniqid(),
            'status' => ReviewJobStatus::Processing->value,
        ]);

        $this->postJson(
            "/api/v1/worker/jobs/{$job->id}/result",
            [
                'status' => ReviewStatus::Failed->value,
                'summary' => 'Critical.',
                'findings' => [
                    [
                        'severity' => FindingSeverity::Critical->value,
                        'title' => 'Critical issue',
                        'file_path' => 'app/Secret.php',
                        'evidence' => 'problem',
                    ],
                ],
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        )->assertOk();

        Mail::assertNothingSent();
    }
}
