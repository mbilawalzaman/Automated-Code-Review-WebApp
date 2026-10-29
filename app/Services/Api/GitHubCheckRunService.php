<?php

namespace App\Services\Api;

use App\Models\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubCheckRunService
{
    public function __construct(
        protected GitHubAppTokenService $tokenService,
        protected RedactionService $redactionService,
    ) {}

    /**
     * Create a pending check run on GitHub (status: in_progress).
     */
    public function createPendingCheckRun(
        Repository $repository,
        string $commitSha,
        ?string $checkName = null
    ): ?string {
        $checkName ??= (string) config('github.check_run_name', 'Automated Code Review');

        $token = $this->tokenService->getAccessToken();
        if (! $token || ! $repository->full_name) {
            Log::info("Skipping GitHub Check Run creation: GitHub App/PAT authentication credentials not configured.");

            return null;
        }

        $url = config('github.api_base')."/repos/{$repository->full_name}/check-runs";

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(10)
                ->post($url, [
                    'name' => $checkName,
                    'head_sha' => $commitSha,
                    'status' => 'in_progress',
                    'started_at' => now()->toIso8601String(),
                    'output' => [
                        'title' => 'Code Review in Progress',
                        'summary' => 'Automated Code Review WebApp is analyzing commits using static security tools (Gitleaks, Semgrep, OSV) and Ollama AI.',
                    ],
                ]);

            if ($response->successful()) {
                $checkRunId = (string) ($response->json('id') ?? '');
                Log::info("Created pending GitHub Check Run #{$checkRunId} for commit {$commitSha}");

                return $checkRunId ?: null;
            }

            Log::warning("GitHub Check Run API returned HTTP {$response->status()}: " . $response->body());
        } catch (\Throwable $e) {
            Log::warning("Failed to create GitHub Check Run gracefully: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Finalize check run on GitHub (status: completed, conclusion: success/failure).
     */
    public function finalizeCheckRun(
        Repository $repository,
        string $checkRunId,
        string $reviewStatus,
        string $summary,
        array $findings = [],
        ?string $detailsUrl = null
    ): bool {
        $token = $this->tokenService->getAccessToken();
        if (! $token || ! $repository->full_name || ! $checkRunId) {
            return false;
        }

        $url = config('github.api_base')."/repos/{$repository->full_name}/check-runs/{$checkRunId}";

        $isFailure = in_array(strtolower($reviewStatus), ['failed', 'error', 'review_error'], true);
        $conclusion = $isFailure ? 'failure' : 'success';

        $findingsCount = count($findings);
        $title = $isFailure
            ? "Automated Code Review: Failed ({$findingsCount} Findings)"
            : "Automated Code Review: Passed ({$findingsCount} Findings)";

        $safeSummary = $this->redactionService->redact($summary);
        $outputMarkdown = "## Executive Review Summary\n\n{$safeSummary}\n\n";

        if ($findingsCount > 0) {
            $outputMarkdown .= "### SAST & Security Findings\n\n";
            foreach (array_slice($findings, 0, 10) as $idx => $f) {
                $num = $idx + 1;
                $sev = strtoupper($f['severity'] ?? 'MEDIUM');
                $file = $f['file_path'] ?? 'Unknown';
                $line = $f['line_number'] ?? $f['line_start'] ?? 1;
                $fTitle = $this->redactionService->redact($f['title'] ?? 'Finding');
                $remed = $this->redactionService->redact(
                    $f['recommendation'] ?? $f['remediation'] ?? 'Review code location.'
                );

                $outputMarkdown .= "**{$num}. [{$sev}] {$fTitle}**\n";
                $outputMarkdown .= "- **File:** `{$file}:{$line}`\n";
                $outputMarkdown .= "- **Remediation:** {$remed}\n\n";
            }
        }

        $payload = [
            'name' => (string) config('github.check_run_name', 'Automated Code Review'),
            'status' => 'completed',
            'completed_at' => now()->toIso8601String(),
            'conclusion' => $conclusion,
            'details_url' => $detailsUrl ?? config('app.url') . '/admin/reports',
            'output' => [
                'title' => $title,
                'summary' => $safeSummary,
                'text' => $outputMarkdown,
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(15)
                ->patch($url, $payload);

            if ($response->successful()) {
                Log::info("Finalized GitHub Check Run #{$checkRunId} as '{$conclusion}'");

                return true;
            }

            Log::warning("GitHub Check Run Finalize API returned HTTP {$response->status()}: " . $response->body());
        } catch (\Throwable $e) {
            Log::warning("Failed to finalize GitHub Check Run gracefully: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Mark a check run cancelled/neutral when an admin cancels the review job (OPS-003).
     */
    public function cancelCheckRun(Repository $repository, string $checkRunId, string $reason): bool
    {
        $token = $this->tokenService->getAccessToken();
        if (! $token || ! $repository->full_name || ! $checkRunId) {
            return false;
        }

        $safeReason = $this->redactionService->redact($reason);
        $payload = [
            'name' => (string) config('github.check_run_name', 'Automated Code Review'),
            'status' => 'completed',
            'completed_at' => now()->toIso8601String(),
            'conclusion' => 'cancelled',
            'output' => [
                'title' => 'Automated Code Review: Cancelled',
                'summary' => $safeReason,
                'text' => "## Review Cancelled\n\n{$safeReason}\n",
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(15)
                ->patch(config('github.api_base')."/repos/{$repository->full_name}/check-runs/{$checkRunId}", $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Failed to cancel GitHub Check Run: '.$e->getMessage());

            return false;
        }
    }
}
