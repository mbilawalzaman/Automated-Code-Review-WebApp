<?php

namespace App\Services\Admin;

class SrsChecklistExtractionService
{
    /**
     * Hard cap so a pathological document cannot generate thousands of rows.
     */
    protected const MAX_ITEMS = 500;

    protected const MANDATORY_MARKERS = ['shall', 'must', 'required', 'mandatory', 'is required to'];

    protected const ADVISORY_MARKERS = ['should', 'may', 'optional', 'recommended', 'nice to have'];

    /**
     * Turn SRS prose into reviewable requirement rows (SRS-003).
     *
     * Extraction is deterministic: the same text always yields the same codes and
     * order, so re-running it cannot silently reshuffle an approved checklist.
     *
     * @return list<array{code: string, title: string, description: string|null, is_mandatory: bool, sort_order: int}>
     */
    public function extract(string $text): array
    {
        $lines = preg_split('/\n/', $text) ?: [];
        $items = [];
        $current = null;

        foreach ($lines as $rawLine) {
            $line = trim(preg_replace('/^[\s>*\-\x{2022}\x{00B7}]+/u', '', $rawLine) ?? $rawLine);

            if ($line === '') {
                continue;
            }

            $parsed = $this->parseRequirementLine($line);

            if ($parsed !== null) {
                if ($current !== null) {
                    $items[] = $current;
                }
                $current = $parsed;

                if (count($items) >= self::MAX_ITEMS) {
                    break;
                }

                continue;
            }

            // Continuation text belongs to the requirement above it.
            if ($current !== null && mb_strlen($line) > 3) {
                $current['description'] = trim(($current['description'] ?? '') . ' ' . $line);
            }
        }

        if ($current !== null && count($items) < self::MAX_ITEMS) {
            $items[] = $current;
        }

        if ($items === []) {
            $items = $this->extractFromSentences($text);
        }

        return $this->finalise($items);
    }

    /**
     * @return array{code: string|null, title: string, description: string|null, is_mandatory: bool}|null
     */
    protected function parseRequirementLine(string $line): ?array
    {
        // Explicit requirement identifiers, e.g. "SEC-001 Secrets shall block approval".
        if (preg_match('/^([A-Z][A-Z0-9]{1,7}-\d{1,4})\s*[:.\-\)]?\s+(.{4,})$/u', $line, $matches)) {
            return [
                'code' => strtoupper($matches[1]),
                'title' => $this->tidyTitle($matches[2]),
                'description' => null,
                'is_mandatory' => $this->isMandatory($matches[2]),
            ];
        }

        // Outline numbering, e.g. "3.2. The system shall ..." or "4) Must support ...".
        if (preg_match('/^(\d+(?:\.\d+){0,3})\s*[:.\-\)]\s+(.{4,})$/u', $line, $matches)) {
            $body = $matches[2];

            if (! $this->looksLikeRequirement($body)) {
                return null;
            }

            return [
                'code' => null,
                'title' => $this->tidyTitle($body),
                'description' => null,
                'is_mandatory' => $this->isMandatory($body),
            ];
        }

        // Unnumbered but clearly normative statements.
        if ($this->looksLikeRequirement($line) && mb_strlen($line) >= 15) {
            return [
                'code' => null,
                'title' => $this->tidyTitle($line),
                'description' => null,
                'is_mandatory' => $this->isMandatory($line),
            ];
        }

        return null;
    }

    /**
     * Last resort for documents written as flowing paragraphs.
     *
     * @return list<array{code: string|null, title: string, description: string|null, is_mandatory: bool}>
     */
    protected function extractFromSentences(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $items = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '' || ! $this->looksLikeRequirement($sentence) || mb_strlen($sentence) < 15) {
                continue;
            }

            $items[] = [
                'code' => null,
                'title' => $this->tidyTitle($sentence),
                'description' => null,
                'is_mandatory' => $this->isMandatory($sentence),
            ];

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * Assign stable codes and ordering, dropping duplicates.
     *
     * @param  list<array{code: string|null, title: string, description: string|null, is_mandatory: bool}>  $items
     * @return list<array{code: string, title: string, description: string|null, is_mandatory: bool, sort_order: int}>
     */
    protected function finalise(array $items): array
    {
        $finalised = [];
        $usedCodes = [];
        $seenTitles = [];
        $generated = 0;

        foreach ($items as $item) {
            $titleKey = mb_strtolower($item['title']);

            if (isset($seenTitles[$titleKey])) {
                continue;
            }
            $seenTitles[$titleKey] = true;

            $code = $item['code'];

            if ($code === null) {
                $generated++;
                $code = 'REQ-' . str_pad((string) $generated, 3, '0', STR_PAD_LEFT);
            }

            // Guarantee uniqueness even when a document repeats an identifier.
            $baseCode = $code;
            $suffix = 1;
            while (isset($usedCodes[$code])) {
                $suffix++;
                $code = $baseCode . '-' . $suffix;
            }
            $usedCodes[$code] = true;

            $description = $item['description'] !== null ? mb_substr($item['description'], 0, 2000) : null;

            $finalised[] = [
                'code' => $code,
                'title' => mb_substr($item['title'], 0, 255),
                'description' => $description,
                'is_mandatory' => $item['is_mandatory'],
                'sort_order' => count($finalised) + 1,
            ];
        }

        return $finalised;
    }

    protected function looksLikeRequirement(string $line): bool
    {
        $haystack = mb_strtolower($line);

        foreach (array_merge(self::MANDATORY_MARKERS, self::ADVISORY_MARKERS) as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function isMandatory(string $line): bool
    {
        $haystack = mb_strtolower($line);

        foreach (self::MANDATORY_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function tidyTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return trim($title, " \t.;:—-");
    }
}
