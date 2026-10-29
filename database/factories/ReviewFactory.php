<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Repository;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'status' => ReviewStatus::Passed,
            'commit_sha' => fake()->sha1(),
            'started_at' => now(),
            'completed_at' => now(),
            'summary' => fake()->sentence(),
        ];
    }
}
