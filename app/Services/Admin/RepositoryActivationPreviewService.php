<?php

namespace App\Services\Admin;

use App\Models\Repository;
use App\Services\Api\GitHubBranchProtectionService;

/**
 * Full ONB-003 activation preview: webhooks, checks, permissions, branch protection.
 */
class RepositoryActivationPreviewService
{
    public function __construct(
        private readonly GitHubBranchProtectionService $branchProtectionService,
    ) {}

    /**
     * @return array{
     *     repository: string,
     *     webhooks: list<array{event: string, url: string, action: string}>,
     *     checks: list<array{name: string, action: string}>,
     *     permissions: list<array{name: string, level: string, reason: string}>,
     *     branch_protection: array<string, mixed>,
     *     notes: list<string>
     * }
     */
    public function preview(Repository $repository): array
    {
        $protection = $this->branchProtectionService->preview($repository);
        $webhookUrl = route('github.webhook');
        $checkName = config('github.check_run_name', 'Automated Code Review');

        return [
            'repository' => $repository->full_name,
            'webhooks' => [
                [
                    'event' => 'push',
                    'url' => $webhookUrl,
                    'action' => 'Deliver push events to the dashboard (creates review jobs).',
                ],
                [
                    'event' => 'pull_request',
                    'url' => $webhookUrl,
                    'action' => 'Deliver PR open/sync/reopen events (creates review jobs + Check Runs).',
                ],
            ],
            'checks' => [
                [
                    'name' => $checkName,
                    'action' => 'Create pending Check Run on PR events; finalize pass/fail when the worker completes.',
                ],
            ],
            'permissions' => [
                [
                    'name' => 'Checks',
                    'level' => 'Read and write',
                    'reason' => 'Create and update the Automated Code Review Check Run.',
                ],
                [
                    'name' => 'Contents',
                    'level' => 'Read-only',
                    'reason' => 'Clone and read repository code for review.',
                ],
                [
                    'name' => 'Pull requests',
                    'level' => 'Read and write',
                    'reason' => 'Post consolidated review comments on pull requests.',
                ],
                [
                    'name' => 'Administration',
                    'level' => 'Read and write',
                    'reason' => 'Apply and update branch protection rules (WF-004).',
                ],
                [
                    'name' => 'Metadata',
                    'level' => 'Read-only',
                    'reason' => 'Required by GitHub Apps for repository listing.',
                ],
            ],
            'branch_protection' => $protection,
            'notes' => [
                'No review runs until you Approve & activate (ONB-001 / ONB-002).',
                'Activation queues a baseline review of the default branch (ONB-008).',
                'Existing CI checks and branch-protection settings are preserved and merged (ONB-004 / AC-002).',
                'Webhooks must already point at this dashboard (or be added manually in GitHub Settings → Webhooks).',
                'GitHub App must be installed on the repository with the permissions listed above.',
            ],
        ];
    }
}
