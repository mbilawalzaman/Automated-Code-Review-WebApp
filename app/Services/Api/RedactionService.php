<?php

namespace App\Services\Api;

/**
 * Redacts sensitive values from findings, emails, GitHub, Trello, and exports (SEC-003).
 */
class RedactionService
{
    /**
     * Patterns that must never appear in outbound or stored evidence.
     *
     * @var array<int, string>
     */
    protected array $patterns = [
        '/(?i)(api[_-]?key|secret|password|token|private[_-]?key)\s*[:=]\s*\S+/',
        '/(?i)Bearer\s+[A-Za-z0-9\-._~+\/]+=*/',
        '/(?i)(AKIA|ASIA)[0-9A-Z]{16}/',
        '/-----BEGIN (?:RSA )?PRIVATE KEY-----[\s\S]*?-----END (?:RSA )?PRIVATE KEY-----/',
        '/(?i)Match:\s*\S+/',
        '/(?i)ghp_[A-Za-z0-9]{20,}/',
        '/(?i)gho_[A-Za-z0-9]{20,}/',
        '/(?i)sk-[A-Za-z0-9]{20,}/',
    ];

    public function redact(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $redacted = $text;
        foreach ($this->patterns as $pattern) {
            $redacted = preg_replace($pattern, '[REDACTED]', $redacted) ?? $redacted;
        }

        return $redacted;
    }

    /**
     * Build a safe one-line evidence snippet (truncated + redacted).
     */
    public function safeEvidenceSnippet(?string $evidence, int $maxLength = 120): string
    {
        $clean = $this->redact($evidence);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        if (strlen($clean) <= $maxLength) {
            return $clean;
        }

        return substr($clean, 0, $maxLength - 3).'...';
    }

    /**
     * Compute a stable finding fingerprint for cross-commit linking (FND-002).
     */
    public function fingerprint(
        ?string $ruleId,
        ?string $filePath,
        ?int $lineNumber = null,
        ?string $title = null,
    ): string {
        $rule = strtolower(trim((string) ($ruleId ?: 'general')));
        $path = strtolower(ltrim(str_replace('\\', '/', trim((string) ($filePath ?: 'unknown'))), './'));
        $line = max(1, (int) ($lineNumber ?: 1));
        $bucket = (int) (floor(($line - 1) / 5) * 5) + 1;
        $titleToken = strtolower(preg_replace('/\s+/', ' ', trim((string) $title)) ?? '');
        $titleToken = substr($titleToken, 0, 40);

        return hash('sha256', "{$rule}|{$path}|{$bucket}|{$titleToken}");
    }
}
