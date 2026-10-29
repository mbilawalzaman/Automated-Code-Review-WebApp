<?php

namespace App\Support;

class WorkerFindingNormalizer
{
    /**
     * Normalize worker finding keys to the shape expected by finalization.
     *
     * @param  array<int, mixed>  $findings
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeList(array $findings): array
    {
        $normalized = [];

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $line = $finding['line_number'] ?? $finding['line_start'] ?? null;
            if ($line === null || ! is_numeric($line) || (int) $line <= 0) {
                $finding['line_number'] = 1;
            } else {
                $finding['line_number'] = (int) $line;
            }

            if (empty($finding['evidence']) && ! empty($finding['description'])) {
                $finding['evidence'] = $finding['description'];
            }

            if (empty($finding['recommendation']) && ! empty($finding['remediation'])) {
                $finding['recommendation'] = $finding['remediation'];
            }

            if (empty($finding['source']) && ! empty($finding['tool_name'])) {
                $finding['source'] = $finding['tool_name'];
            }

            if (! isset($finding['objective_id']) && isset($finding['srs_objective_id'])) {
                $finding['objective_id'] = $finding['srs_objective_id'];
            }

            if (empty($finding['severity'])) {
                $finding['severity'] = 'medium';
            }
            if (empty($finding['file_path'])) {
                $finding['file_path'] = 'Unknown';
            }
            if (empty($finding['title'])) {
                $finding['title'] = 'Automated Finding';
            }

            $normalized[] = $finding;
        }

        return $normalized;
    }
}
