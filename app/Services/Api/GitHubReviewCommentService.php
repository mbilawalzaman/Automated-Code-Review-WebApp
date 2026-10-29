<?php

namespace App\Services\Api;

use App\Models\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubReviewCommentService
{
    public function __construct(
        protected GitHubAppTokenService $tokenService,
        protected RedactionService $redactionService,
    ) {}

    /**
     * Post a single consolidated PR review comment to GitHub (GIT-002 / GIT-003).
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, array<string, mixed>>  $resolvedFindings
     */
    public function postConsolidatedReview(
        Repository $repository,
        int $pullRequestNumber,
        string $commitSha,
        string $reviewStatus,
        string $summary,
        array $findings = [],
        ?string $reportUrl = null,
        array $resolvedFindings = [],
    ): bool {
        $token = $this->tokenService->getAccessToken();
        if (! $token || ! $repository->full_name || ! $pullRequestNumber) {
            return false;
        }

        $url = config('github.api_base')."/repos/{$repository->full_name}/pulls/{$pullRequestNumber}/reviews";

        $isFailure = in_array(strtolower($reviewStatus), ['failed', 'error', 'review_error'], true);
        $event = $isFailure ? 'REQUEST_CHANGES' : 'COMMENT';

        $findingsCount = count($findings);
        $statusEmoji = $isFailure ? '❌' : '✅';
        $statusText = strtoupper($reviewStatus);
        $safeSummary = $this->redactionService->redact($summary);

        $body = "### Automated Code Review Results {$statusEmoji}\n\n";
        $body .= "**Status:** `{$statusText}` | **Findings Detected:** `{$findingsCount}`\n\n";
        $body .= "#### Executive Summary\n{$safeSummary}\n\n";

        if ($findingsCount > 0) {
            $body .= "#### Findings & Remediation Guidance\n\n";
            foreach (array_slice($findings, 0, 10) as $idx => $f) {
                $num = $idx + 1;
                $sev = strtoupper($f['severity'] ?? 'MEDIUM');
                $file = $f['file_path'] ?? 'Unknown';
                $line = $f['line_number'] ?? $f['line_start'] ?? 1;
                $fTitle = $this->redactionService->redact($f['title'] ?? 'Finding');
                $remed = $this->redactionService->redact(
                    $f['recommendation'] ?? $f['remediation'] ?? 'Review code location.'
                );

                $body .= "**{$num}. [{$sev}] {$fTitle}**\n";
                $body .= "- **Location:** `{$file}:{$line}`\n";
                $body .= "- **Remediation:** {$remed}\n\n";
            }
        }

        if ($resolvedFindings !== []) {
            $body .= "#### Resolved Since Previous Review\n\n";
            foreach (array_slice($resolvedFindings, 0, 10) as $idx => $f) {
                $num = $idx + 1;
                $file = $f['file_path'] ?? 'Unknown';
                $line = $f['line_number'] ?? 'N/A';
                $fTitle = $this->redactionService->redact($f['title'] ?? 'Finding');
                $body .= "**{$num}. {$fTitle}** — `{$file}:{$line}` (verified fixed)\n";
            }
            $body .= "\n";
        }

        if ($reportUrl) {
            $body .= "---\n📄 [View Full PDF Review Report]({$reportUrl})\n";
        }

        $payload = [
            'commit_id' => $commitSha,
            'body' => $body,
            'event' => $event,
        ];

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(15)
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info("Posted consolidated PR review for PR #{$pullRequestNumber} on {$repository->full_name}");

                return true;
            }

            Log::warning("GitHub PR Review API returned HTTP {$response->status()}: " . $response->body());
        } catch (\Throwable $e) {
            Log::warning("Failed to post consolidated GitHub PR review gracefully: " . $e->getMessage());
        }

        return false;
    }
}
