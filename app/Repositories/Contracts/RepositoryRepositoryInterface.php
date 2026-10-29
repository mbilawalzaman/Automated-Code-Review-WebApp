<?php

namespace App\Repositories\Contracts;

use App\Models\Repository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface RepositoryRepositoryInterface
{
    /**
     * @return Collection<int, Repository>
     */
    public function all(): Collection;

    /**
     * @return LengthAwarePaginator<int, Repository>
     */
    public function paginate(int $perPage): LengthAwarePaginator;

    public function count(): int;

    public function findById(int $id): ?Repository;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Repository;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Repository $repository, array $data): Repository;

    public function delete(Repository $repository): bool;
}
