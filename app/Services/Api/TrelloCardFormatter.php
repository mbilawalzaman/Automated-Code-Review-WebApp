<?php

namespace App\Services\Api;

class TrelloCardFormatter
{
    public function __construct(
        protected RedactionService $redactionService,
    ) {}

    /**
     * Format Trello Card Title according to Client SOP (One emoji, Title Case).
     */
    public function formatTitle(string $reviewStatus, ?string $prTitle = null, ?int $prNumber = null, ?string $branchName = null): string
    {
        $isFailure = in_array(strtolower($reviewStatus), ['failed', 'error', 'review_error'], true);
        $emoji = $isFailure ? '🔴' : '📊';

        $prefix = $isFailure ? 'Security Review' : 'Code Review';

        if ($prNumber) {
            $subject = "PR #{$prNumber}";
        } elseif ($branchName) {
            $subject = mb_strlen($branchName) > 20
                ? mb_substr($branchName, 0, 17).'...'
                : $branchName;
        } else {
            $subject = 'Code Commit';
        }

        $rawTitle = "{$prefix}: {$subject}";
        $titleCased = $this->toTitleCase($rawTitle);

        return "{$emoji} {$titleCased}";
    }

    /**
     * Format Trello Card Comment / Description Body per Client SOP with hidden HTML marker.
     */
    public function formatComment(
        string $reviewStatus,
        string $summary,
        array $findings = [],
        ?string $reportUrl = null
    ): string {
        $isFailure = in_array(strtolower($reviewStatus), ['failed', 'error', 'review_error'], true);
        $statusEmoji = $isFailure ? '❌' : '✅';
        $statusText = strtoupper($reviewStatus);
        $findingsCount = count($findings);
        $safeSummary = $this->redactionService->redact($summary);

        $body = "<!-- CodeReviewAutomation -->\n";
        $body .= "### Automated Code Review Summary {$statusEmoji}\n\n";
        $body .= "**Status:** `{$statusText}` | **Total Findings:** `{$findingsCount}`\n\n";
        $body .= "#### Executive Overview\n{$safeSummary}\n\n";

        if ($findingsCount > 0) {
            $body .= "#### Affected Components & Remediation Guidance\n\n";
            foreach (array_slice($findings, 0, 10) as $idx => $f) {
                $num = $idx + 1;
                $sev = strtoupper($f['severity'] ?? 'MEDIUM');
                $file = $f['file_path'] ?? 'Unknown';
                $line = $f['line_number'] ?? $f['line_start'] ?? 1;
                $fTitle = $this->redactionService->redact($f['title'] ?? 'Finding');
                $remed = $this->redactionService->redact(
                    $f['recommendation'] ?? $f['remediation'] ?? 'Review affected code component.'
                );

                $body .= "**{$num}. [{$sev}] {$fTitle}**\n";
                $body .= "- **Location:** `{$file}:{$line}`\n";
                $body .= "- **Remediation:** {$remed}\n\n";
            }
        }

        if ($reportUrl) {
            $body .= "---\n📄 [View Full PDF Review Report]({$reportUrl})\n";
        }

        return $body;
    }

    public function toTitleCase(string $string): string
    {
        $lowercaseWords = [
            'a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'so', 'yet',
            'at', 'by', 'in', 'of', 'on', 'to', 'up', 'as', 'into', 'with', 'from',
        ];

        $words = preg_split('/\s+/', trim($string));
        $count = count($words);

        $result = [];
        foreach ($words as $idx => $word) {
            $lower = strtolower($word);

            if (preg_match('/^[A-Z0-9#-]+$/', $word) && strlen($word) <= 5) {
                $result[] = $word;
                continue;
            }

            if ($idx === 0 || $idx === $count - 1 || ! in_array($lower, $lowercaseWords, true)) {
                $result[] = ucfirst($lower);
            } else {
                $result[] = $lower;
            }
        }

        return implode(' ', $result);
    }
}
