<?php

namespace Database\Seeders;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\IntegrationType;
use App\Enums\RepositoryStatus;

use App\Enums\ReviewJobStatus;
use App\Enums\ReviewStatus;

use App\Enums\SrsDocumentStatus;
use App\Enums\UserRole;

use App\Models\AuditLog;
use App\Models\Branch;

use App\Models\Finding;
use App\Models\Integration;

use App\Models\Notification;
use App\Models\Objective;

use App\Models\Project;
use App\Models\Report;

use App\Models\Repository;
use App\Models\Review;

use App\Models\ReviewJob;
use App\Models\SrsDocument;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@qaplatform.test'],
            [
                'name' => 'QA Super Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::SuperAdmin,
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('super_admin');

        $manager = User::query()->updateOrCreate(
            ['email' => 'manager@qaplatform.test'],
            [
                'name' => 'QA Project Manager',
                'password' => Hash::make('password'),
                'role' => UserRole::ProjectManager,
                'email_verified_at' => now(),
            ]
        );
        $manager->assignRole('project_manager');

        $auditor = User::query()->updateOrCreate(
            ['email' => 'auditor@qaplatform.test'],
            [
                'name' => 'QA Security Auditor',
                'password' => Hash::make('password'),
                'role' => UserRole::SecurityAuditor,
                'email_verified_at' => now(),
            ]
        );
        $auditor->assignRole('security_auditor');

        $developer = User::query()->updateOrCreate(
            ['email' => 'developer@qaplatform.test'],
            [
                'name' => 'QA Developer',
                'password' => Hash::make('password'),
                'role' => UserRole::Developer,
                'email_verified_at' => now(),
            ]
        );
        $developer->assignRole('developer');

        $projects = [
            [
                'name' => 'Acme Payments API',
                'slug' => 'acme-payments-api',
                'description' => 'Core payment processing service reviewed on every pull request.',
                'repositories' => [
                    [
                        'name' => 'acme-payments-api',
                        'full_name' => 'acme-org/acme-payments-api',
                        'status' => RepositoryStatus::Active,
                        'branches' => ['main', 'develop'],
                    ],
                    [
                        'name' => 'acme-payments-docs',
                        'full_name' => 'acme-org/acme-payments-docs',
                        'status' => RepositoryStatus::Connected,
                        'branches' => ['main'],
                    ],
                    [
                        'name' => 'Automated-Code-Review-WebApp',
                        'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
                        'status' => RepositoryStatus::Active,
                        'branches' => ['main', 'staging', 'feature/github-integration'],
                    ],
                ],
            ],
            [
                'name' => 'Retail Checkout Portal',
                'slug' => 'retail-checkout-portal',
                'description' => 'Customer-facing checkout UI with automated SRS compliance checks.',
                'repositories' => [
                    [
                        'name' => 'retail-checkout-portal',
                        'full_name' => 'retail-team/retail-checkout-portal',
                        'status' => RepositoryStatus::Active,
                        'branches' => ['main', 'release/2.4'],
                    ],
                ],
            ],
            [
                'name' => 'Internal Admin Tools',
                'slug' => 'internal-admin-tools',
                'description' => 'Legacy admin panel scheduled for incremental review rollout.',
                'repositories' => [
                    [
                        'name' => 'internal-admin-tools',
                        'full_name' => 'platform-team/internal-admin-tools',
                        'status' => RepositoryStatus::Paused,
                        'branches' => ['main'],
                        'is_paused' => true,
                    ],
                ],
            ],
        ];

        foreach ($projects as $projectData) {
            $this->seedProject($admin, $projectData);
        }

        $this->command?->info('Demo data seeded successfully.');
        $this->command?->info('Login: admin@qaplatform.test / password');
    }

    /**
     * @param  array<string, mixed>  $projectData
     */
    private function seedProject(User $admin, array $projectData): void
    {
        $project = Project::query()->firstOrCreate(
            ['slug' => $projectData['slug']],
            [
                'name' => $projectData['name'],
                'description' => $projectData['description'],
                'metadata' => ['environment' => 'demo'],
                'is_active' => true,
                'created_by' => $admin->id,
            ]
        );

        Integration::query()->firstOrCreate(
            ['project_id' => $project->id, 'type' => IntegrationType::GitHub],
            [
                'name' => "{$project->slug}-github",
                'credentials' => ['installation_id' => rand(10000, 99999)],
                'config' => ['org' => Str::before($projectData['repositories'][0]['full_name'], '/')],
                'is_active' => true,
                'health_status' => 'healthy',
                'last_synced_at' => now()->subHours(2),
            ]
        );

        Integration::query()->firstOrCreate(
            ['project_id' => $project->id, 'type' => IntegrationType::Trello],
            [
                'name' => "{$project->slug}-trello",
                'config' => ['board_id' => Str::uuid()->toString()],
                'is_active' => true,
                'health_status' => 'healthy',
                'last_synced_at' => now()->subDay(),
            ]
        );

        $srsDocument = SrsDocument::query()->firstOrCreate(
            ['project_id' => $project->id, 'version' => 1],
            [
                'title' => "{$project->name} SRS v1.0",
                'source_type' => 'google_drive',
                'source_url' => 'https://drive.google.com/file/d/demo-srs',
                'content_hash' => hash('sha256', $project->slug),
                'status' => SrsDocumentStatus::Approved,
                'checklist' => [
                    'security_requirements' => true,
                    'performance_requirements' => true,
                    'api_contracts' => true,
                ],
                'approved_by' => $admin->id,
                'approved_at' => now()->subWeek(),
            ]
        );

        $objectives = collect([
            ['code' => 'SEC-001', 'title' => 'Authenticate all protected endpoints'],
            ['code' => 'SEC-002', 'title' => 'Validate and sanitize user input'],
            ['code' => 'PERF-001', 'title' => 'Keep API p95 latency under 300ms'],
        ])->map(function (array $objective, int $index) use ($project, $srsDocument, $admin) {
            return Objective::query()->firstOrCreate(
                ['project_id' => $project->id, 'code' => $objective['code']],
                [
                    'srs_document_id' => $srsDocument->id,
                    'title' => $objective['title'],
                    'description' => "Demo objective: {$objective['title']}",
                    'is_mandatory' => true,
                    'sort_order' => $index + 1,
                    'is_approved' => true,
                    'approved_by' => $admin->id,
                    'approved_at' => now()->subWeek(),
                ]
            );
        });

        foreach ($projectData['repositories'] as $index => $repositoryData) {
            $this->seedRepository(
                $admin,
                $project,
                $srsDocument,
                $objectives,
                $repositoryData,
                $index === 0,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $repositoryData
     */
    private function seedRepository(
        User $admin,
        Project $project,
        SrsDocument $srsDocument,
        $objectives,
        array $repositoryData,
        bool $createRichHistory = false
    ): void {
        $repository = Repository::query()->firstOrCreate(
            ['full_name' => $repositoryData['full_name']],
            [
                'project_id' => $project->id,
                'name' => $repositoryData['name'],
                'github_repo_id' => rand(100000, 999999),
                'status' => $repositoryData['status'],
                'default_branch' => $repositoryData['branches'][0],
                'is_paused' => $repositoryData['is_paused'] ?? false,
                'review_rules' => [
                    'static_first' => true,
                    'ai_enabled' => true,
                    'block_on_critical' => true,
                ],
                'notification_settings' => [
                    'email_on_critical' => true,
                    'trello_digest' => true,
                ],
            ]
        );

        foreach ($repositoryData['branches'] as $branchName) {
            Branch::query()->firstOrCreate(
                ['repository_id' => $repository->id, 'name' => $branchName],
                [
                    'is_protected' => in_array($branchName, ['main', 'master', 'staging'], true),
                    'review_enabled' => true,
                ]
            );
        }

        if (! $createRichHistory) {
            return;
        }

        $reviewData = [
            [
                'pr' => 101,
                'branch' => 'feature/payments-v2',
                'sha' => '8f2d1e0a9b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e',
                'title' => 'Feature: Add Payment Gateway V2 API',
                'status' => ReviewStatus::Failed,
                'summary' => 'Critical security vulnerability detected: hardcoded secret in config and SQL injection risk.',
                'tools' => ['semgrep', 'gitleaks', 'phpstan'],
                'findings' => [
                    [
                        'severity' => FindingSeverity::Critical,
                        'status' => FindingStatus::Open,
                        'file' => 'app/Services/PaymentService.php',
                        'line' => 42,
                        'title' => 'Hardcoded Stripe API Secret Key',
                        'evidence' => '$apiKey = "sk_live_51NxXXXXXXXXXXXXXX";',
                        'recommendation' => 'Store API keys in environment variables via config/services.php.',
                        'is_blocking' => true,
                        'objective_id' => $objectives->firstWhere('code', 'SEC-001')?->id,
                    ],
                    [
                        'severity' => FindingSeverity::High,
                        'status' => FindingStatus::Open,
                        'file' => 'app/Http/Controllers/PaymentController.php',
                        'line' => 88,
                        'title' => 'Unsanitized Raw SQL Query Construction',
                        'evidence' => 'DB::select("SELECT * FROM transactions WHERE id = " . $request->id);',
                        'recommendation' => 'Use parameterized PDO bindings: DB::select("SELECT * FROM transactions WHERE id = ?", [$request->id]);',
                        'is_blocking' => true,
                        'objective_id' => $objectives->firstWhere('code', 'SEC-002')?->id,
                    ],
                ],
            ],
            [
                'pr' => 102,
                'branch' => 'feature/webhook-retry',
                'sha' => '1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b',
                'title' => 'Feature: Exponential Backoff Webhook Retries',
                'status' => ReviewStatus::PassedWithWarnings,
                'summary' => 'Review passed with 2 code quality warnings. No blocking security findings.',
                'tools' => ['semgrep', 'phpstan', 'phpunit'],
                'findings' => [
                    [
                        'severity' => FindingSeverity::Medium,
                        'status' => FindingStatus::Accepted,
                        'file' => 'app/Jobs/ProcessWebhookJob.php',
                        'line' => 65,
                        'title' => 'Missing Type Hint on Public Method Return',
                        'evidence' => 'public function handle()',
                        'recommendation' => 'Add explicit void return type declaration: public function handle(): void',
                        'is_blocking' => false,
                        'objective_id' => null,
                    ],
                    [
                        'severity' => FindingSeverity::Low,
                        'status' => FindingStatus::Open,
                        'file' => 'config/webhooks.php',
                        'line' => 12,
                        'title' => 'Unused Configuration Variable',
                        'evidence' => "'deprecated_timeout' => 30,",
                        'recommendation' => 'Remove deprecated configuration entry to maintain clean config state.',
                        'is_blocking' => false,
                        'objective_id' => null,
                    ],
                ],
            ],
            [
                'pr' => 103,
                'branch' => 'fix/null-pointer-checkout',
                'sha' => '3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d',
                'title' => 'Fix: Guard Against Null Customer ID in Checkout',
                'status' => ReviewStatus::Passed,
                'summary' => 'All mandatory checks passed. 0 security findings, 100% SRS compliance.',
                'tools' => ['semgrep', 'gitleaks', 'phpstan', 'phpunit'],
                'findings' => [],
            ],
        ];

        foreach ($reviewData as $rData) {
            $idempotencyKey = 'demo-' . $rData['sha'] . '-' . $repository->id;
            $reviewJob = ReviewJob::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'repository_id' => $repository->id,
                    'commit_sha' => $rData['sha'],
                    'trigger_type' => 'pull_request',
                    'pull_request_number' => $rData['pr'],
                    'status' => ReviewJobStatus::Completed->value,
                    'payload' => ['title' => $rData['title'], 'branch' => $rData['branch']],
                    'started_at' => now()->subHours(5),
                    'completed_at' => now()->subHours(4),
                ]
            );

            $review = Review::query()->firstOrCreate(
                ['repository_id' => $repository->id, 'commit_sha' => $rData['sha']],
                [
                    'review_job_id' => $reviewJob->id,
                    'pull_request_number' => $rData['pr'],
                    'pull_request_title' => $rData['title'],
                    'branch_id' => $repository->branches()->where('name', $rData['branch'])->first()?->id,
                    'author' => 'dev_user',
                    'status' => $rData['status'],
                    'summary' => $rData['summary'],
                    'tools_used' => $rData['tools'],
                    'github_check_run_id' => (string) rand(1000000, 9999999),
                    'completed_at' => now()->subHours(4),
                ]
            );

            foreach ($rData['findings'] as $fData) {
                Finding::query()->firstOrCreate(
                    [
                        'review_id' => $review->id,
                        'file_path' => $fData['file'],
                        'line_number' => $fData['line'],
                    ],
                    [
                        'objective_id' => $fData['objective_id'],
                        'severity' => $fData['severity'],
                        'status' => $fData['status'],
                        'title' => $fData['title'],
                        'evidence' => $fData['evidence'],
                        'recommendation' => $fData['recommendation'],
                        'is_blocking' => $fData['is_blocking'],
                    ]
                );
            }
        }

        AuditLog::query()->firstOrCreate(
            ['target_id' => $repository->id, 'action' => "Repository connected: {$repository->full_name}"],
            [
                'user_id' => $admin->id,
                'category' => \App\Enums\AuditCategory::Repository,
                'event_type' => \App\Enums\AuditEventType::Created,
                'severity' => \App\Enums\AuditSeverity::Info,
                'target_type' => Repository::class,
                'after_state' => ['full_name' => $repository->full_name, 'status' => 'connected'],
                'metadata' => ['full_name' => $repository->full_name, 'environment' => 'demo'],
                'reason' => 'Repository connected during initial project onboarding',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            ]
        );

        Notification::query()->firstOrCreate(
            ['user_id' => $admin->id, 'type' => 'security_alert'],
            [
                'channel' => 'email',
                'subject' => 'Critical Finding Detected',
                'body' => "Critical security alert for {$repository->full_name}",
                'status' => 'sent',
                'sent_at' => now(),
            ]
        );

        Report::query()->firstOrCreate(
            ['repository_id' => $repository->id, 'type' => 'security_summary'],
            [
                'project_id' => $project->id,
                'format' => 'pdf',
                'generated_by' => $admin->id,
                'metadata' => ['total_findings' => 4, 'critical' => 1, 'high' => 1],
                'file_path' => 'reports/demo_security_report.pdf',
            ]
        );
    }
}
