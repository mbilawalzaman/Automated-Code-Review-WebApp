<?php

namespace App\Repositories\Contracts;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProjectRepositoryInterface
{
    /**
     * @return Collection<int, Project>
     */
    public function all(): Collection;

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginate(int $perPage): LengthAwarePaginator;

    public function count(): int;

    public function findById(int $id): ?Project;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Project;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project;

    public function delete(Project $project): bool;
}
