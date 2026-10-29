<?php

namespace App\Events\Review;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReviewOverriddenEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{documentation_url?: ?string, documentation_notes?: ?string}  $context
     */
    public function __construct(
        public Review $review,
        public string $reason,
        public User $actor,
        public array $context = [],
    ) {}
}
