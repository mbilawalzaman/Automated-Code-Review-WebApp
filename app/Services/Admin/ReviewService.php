<?php

namespace App\Services\Admin;

use App\Enums\ReviewStatus;
use App\Events\Review\ReviewOverriddenEvent;
use App\Models\Review;
use App\Models\User;
use App\Services\Api\GitHubCheckRunService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ReviewService
{
    public function __construct(
        protected ChangeClassGatingService $changeClassGatingService,
        protected GitHubCheckRunService $checkRunService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    public function list(int $perPage = 10): LengthAwarePaginator
    {
        return Review::query()
            ->with(['repository.project', 'branch'])
            ->withCount('findings')
            ->latest('completed_at')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{documentation_url?: ?string, documentation_notes?: ?string}  $context
     */
    public function override(Review $review, string $reason, User $actor, array $context = []): Review
    {
        if ($review->status !== ReviewStatus::Failed) {
            throw new InvalidArgumentException('Only failed reviews can be overridden.');
        }

        if ($review->overridden_at !== null) {
            throw new InvalidArgumentException('This review has already been overridden.');
        }

        $this->changeClassGatingService->assertOverrideAllowed($review, $context);

        $review->update([
            'overridden_by' => $actor->id,
            'override_reason' => $reason,
            'overridden_at' => now(),
        ]);

        $review->refresh();

        ReviewOverriddenEvent::dispatch($review, $reason, $actor, $context);

        $this->finalizeGitHubCheckRunOnOverride($review, $reason);

        return $review;
    }

    protected function finalizeGitHubCheckRunOnOverride(Review $review, string $reason): void
    {
        $checkRunId = $review->github_check_run_id;
        if (! $checkRunId) {
            return;
        }

        $review->loadMissing('repository');
        $repository = $review->repository;

        if (! $repository) {
            return;
        }

        try {
            $this->checkRunService->finalizeCheckRun(
                $repository,
                (string) $checkRunId,
                ReviewStatus::Passed->value,
                'PM override applied: '.$reason,
                [],
                config('app.url').'/admin/reviews'
            );
        } catch (\Throwable $e) {
            Log::warning("GitHub Check Run update after override failed for Review #{$review->id}: {$e->getMessage()}");
        }
    }
}
