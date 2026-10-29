<?php

namespace Database\Factories;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 */
class FindingFactory extends Factory
{
    protected $model = Finding::class;

    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'severity' => FindingSeverity::Medium,
            'status' => FindingStatus::Open,
            'file_path' => 'app/Example.php',
            'line_number' => fake()->numberBetween(1, 200),
            'title' => fake()->sentence(),
            'evidence' => fake()->paragraph(),
            'recommendation' => fake()->sentence(),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'is_blocking' => false,
        ];
    }
}
