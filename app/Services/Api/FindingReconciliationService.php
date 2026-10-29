<?php

namespace App\Services\Api;

use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Models\Review;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Links recurring findings across commits and auto-resolves verified fixes (FND-002 / FND-003 / GIT-003).
 */
class FindingReconciliationService
{
    public function __construct(
        protected RedactionService $redactionService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $findingsToCreate
     * @return array{findings: array<int, array<string, mixed>>, resolved: Collection<int, Finding>}
     */
    public function reconcile(Review $review, array $findingsToCreate): array
    {
        $repositoryId = (int) $review->repository_id;
        $currentFingerprints = [];

        foreach ($findingsToCreate as $index => $finding) {
            $fingerprint = $finding['fingerprint']
                ?? $this->redactionService->fingerprint(
                    $finding['rule_id'] ?? null,
                    $finding['file_path'] ?? null,
                    isset($finding['line_number']) ? (int) $finding['line_number'] : null,
                    $finding['title'] ?? null,
                );

            $findingsToCreate[$index]['fingerprint'] = $fingerprint;
            $currentFingerprints[] = $fingerprint;
        }

        $uniqueFingerprints = array_values(array_unique(array_filter($currentFingerprints)));
        $parentsByFingerprint = $this->latestOpenParentsByFingerprint($repositoryId, (int) $review->id, $uniqueFingerprints);

        foreach ($findingsToCreate as $index => $finding) {
            $fingerprint = $finding['fingerprint'] ?? null;
            if ($fingerprint && isset($parentsByFingerprint[$fingerprint])) {
                $findingsToCreate[$index]['parent_finding_id'] = $parentsByFingerprint[$fingerprint]->id;
            }
        }

        $resolved = $this->resolvePriorsNotIn($review, $currentFingerprints);

        return [
            'findings' => $findingsToCreate,
            'resolved' => $resolved,
        ];
    }

    /**
     * Attach parent_finding_id for rows about to be inserted (chunked ingest).
     *
     * @param  array<int, array<string, mixed>>  $findingsToCreate
     * @return array<int, array<string, mixed>>
     */
    public function attachParents(Review $review, array $findingsToCreate): array
    {
        $repositoryId = (int) $review->repository_id;
        $fingerprints = [];

        foreach ($findingsToCreate as $index => $finding) {
            $fingerprint = $finding['fingerprint']
                ?? $this->redactionService->fingerprint(
                    $finding['rule_id'] ?? null,
                    $finding['file_path'] ?? null,
                    isset($finding['line_number']) ? (int) $finding['line_number'] : null,
                    $finding['title'] ?? null,
                );
            $findingsToCreate[$index]['fingerprint'] = $fingerprint;
            $fingerprints[] = $fingerprint;
        }

        $parentsByFingerprint = $this->latestOpenParentsByFingerprint(
            $repositoryId,
            (int) $review->id,
            array_values(array_unique(array_filter($fingerprints)))
        );

        foreach ($findingsToCreate as $index => $finding) {
            $fingerprint = $finding['fingerprint'] ?? null;
            if ($fingerprint && isset($parentsByFingerprint[$fingerprint])) {
                $findingsToCreate[$index]['parent_finding_id'] = $parentsByFingerprint[$fingerprint]->id;
            }
        }

        return $findingsToCreate;
    }

    /**
     * After findings are already persisted, close prior opens that no longer appear.
     *
     * @return Collection<int, Finding>
     */
    public function reconcilePersistedFindings(Review $review): Collection
    {
        $currentFingerprints = Finding::query()
            ->where('review_id', $review->id)
            ->whereNotNull('fingerprint')
            ->pluck('fingerprint')
            ->filter()
            ->values()
            ->all();

        return $this->resolvePriorsNotIn($review, $currentFingerprints);
    }

    /**
     * @param  list<string>  $currentFingerprints
     * @return Collection<int, Finding>
     */
    protected function resolvePriorsNotIn(Review $review, array $currentFingerprints): Collection
    {
        $repositoryId = (int) $review->repository_id;

        $openPrior = Finding::query()
            ->where('status', FindingStatus::Open->value)
            ->whereHas('review', fn ($q) => $q->where('repository_id', $repositoryId))
            ->where('review_id', '!=', $review->id)
            ->whereNotNull('fingerprint')
            ->get();

        $toResolve = $openPrior->filter(
            fn (Finding $prior) => $prior->fingerprint
                && ! in_array($prior->fingerprint, $currentFingerprints, true)
        );

        if ($toResolve->isEmpty()) {
            return collect();
        }

        $resolvedAt = Carbon::now();
        $ids = $toResolve->pluck('id')->all();

        Finding::query()->whereIn('id', $ids)->update([
            'status' => FindingStatus::Resolved->value,
            'resolved_in_review_id' => $review->id,
            'resolved_at' => $resolvedAt,
            'is_blocking' => false,
            'updated_at' => $resolvedAt,
        ]);

        return $toResolve->map(function (Finding $prior) use ($review, $resolvedAt) {
            $prior->forceFill([
                'status' => FindingStatus::Resolved,
                'resolved_in_review_id' => $review->id,
                'resolved_at' => $resolvedAt,
                'is_blocking' => false,
            ]);

            return $prior;
        })->values();
    }

    /**
     * @param  list<string>  $fingerprints
     * @return array<string, Finding>
     */
    protected function latestOpenParentsByFingerprint(int $repositoryId, int $reviewId, array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $parents = Finding::query()
            ->whereIn('fingerprint', $fingerprints)
            ->where('status', FindingStatus::Open->value)
            ->whereHas('review', fn ($q) => $q->where('repository_id', $repositoryId))
            ->where('review_id', '!=', $reviewId)
            ->orderByDesc('id')
            ->get();

        $byFingerprint = [];
        foreach ($parents as $parent) {
            $fp = (string) $parent->fingerprint;
            if ($fp !== '' && ! isset($byFingerprint[$fp])) {
                $byFingerprint[$fp] = $parent;
            }
        }

        return $byFingerprint;
    }
}
