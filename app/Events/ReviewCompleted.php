<?php

namespace App\Events;

use App\Models\Review;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReviewCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Review $review,
        public array $findings = []
    ) {}
}
