<?php

namespace Database\Factories;

use App\Enums\RepositoryStatus;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'project_id' => Project::factory(),
            'name' => $name,
            'full_name' => 'org/'.$name,
            'github_repo_id' => fake()->unique()->numberBetween(100000, 999999),
            'status' => RepositoryStatus::Disconnected,
            'is_paused' => false,
            'default_branch' => 'main',
        ];
    }
}
