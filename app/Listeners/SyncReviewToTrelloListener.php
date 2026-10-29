<?php

namespace App\Listeners;

use App\Events\ReviewCompleted;
use App\Services\Api\TrelloService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SyncReviewToTrelloListener
{
    public function __construct(
        protected TrelloService $trelloService
    ) {}

    /**
     * Handle the ReviewCompleted event.
     */
    public function handle(ReviewCompleted $event): void
    {
        $review = $event->review;
        $repository = $review->repository;

        if (! $repository) {
            return;
        }

        try {
            $this->trelloService->syncReviewToTrello($repository, $review, $event->findings);
        } catch (\Throwable $e) {
            Log::warning("SyncReviewToTrelloListener caught exception: " . $e->getMessage());
        }
    }
}
