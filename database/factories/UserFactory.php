<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Developer,
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->roles()->count() === 0 && $user->role) {
                $roleName = $user->role->value;
                $role = Role::query()->where('name', $roleName)->first();
                if ($role) {
                    $user->roles()->sync([$role->id]);
                }
            }
        });
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function projectManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::ProjectManager,
        ]);
    }

    public function securityAuditor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SecurityAuditor,
        ]);
    }

    public function developer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Developer,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
