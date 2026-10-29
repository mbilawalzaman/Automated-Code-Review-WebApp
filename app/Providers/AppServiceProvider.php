<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use App\Repositories\Contracts\FindingRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\RepositoryRepositoryInterface;
use App\Repositories\Contracts\ReviewJobRepositoryInterface;
use App\Repositories\Contracts\ReviewRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\FindingRepository;
use App\Repositories\Eloquent\ProjectRepository;
use App\Repositories\Eloquent\RepositoryRepository;
use App\Repositories\Eloquent\ReviewJobRepository;
use App\Repositories\Eloquent\ReviewRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProjectRepositoryInterface::class, ProjectRepository::class);
        $this->app->bind(RepositoryRepositoryInterface::class, RepositoryRepository::class);
        $this->app->bind(ReviewRepositoryInterface::class, ReviewRepository::class);
        $this->app->bind(FindingRepositoryInterface::class, FindingRepository::class);
        $this->app->bind(ReviewJobRepositoryInterface::class, ReviewJobRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            $knownPermissions = once(
                fn (): array => Permission::query()->pluck('name')->all()
            );

            if (! in_array($ability, $knownPermissions, true)) {
                return null;
            }

            return $user->hasPermissionTo($ability);
        });
    }
}
