<?php

namespace App\Listeners;

use App\Events\ReviewCompleted;
use App\Services\Api\CriticalEmailNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends critical review alert emails synchronously (no ShouldQueue).
 * Safe on shared hosting without php artisan queue:work.
 */
class SendCriticalReviewEmailListener
{
    public function __construct(
        protected CriticalEmailNotificationService $criticalEmailNotificationService
    ) {}

    public function handle(ReviewCompleted $event): void
    {
        try {
            $this->criticalEmailNotificationService->sendIfCritical(
                $event->review,
                $event->findings
            );
        } catch (Throwable $e) {
            Log::warning('SendCriticalReviewEmailListener failed: '.$e->getMessage(), [
                'review_id' => $event->review->id ?? null,
            ]);
        }
    }
}
