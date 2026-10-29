<?php

namespace App\Repositories\Eloquent;

use App\Models\Project;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ProjectRepository implements ProjectRepositoryInterface
{
    public function all(): Collection
    {
        return Project::query()
            ->orderBy('name')
            ->get();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Project::query()
            ->withCount('repositories')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function count(): int
    {
        return Project::query()->count();
    }

    public function findById(int $id): ?Project
    {
        return Project::query()->find($id);
    }

    public function create(array $data): Project
    {
        return Project::query()->create($data);
    }

    public function update(Project $project, array $data): Project
    {
        $project->update($data);

        return $project->fresh();
    }

    public function delete(Project $project): bool
    {
        return (bool) $project->delete();
    }
}
