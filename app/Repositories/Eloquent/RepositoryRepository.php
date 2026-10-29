<?php

namespace App\Repositories\Eloquent;

use App\Models\Repository;
use App\Repositories\Contracts\RepositoryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class RepositoryRepository implements RepositoryRepositoryInterface
{
    public function all(): Collection
    {
        return Repository::query()
            ->with(['project', 'branches'])
            ->orderBy('full_name')
            ->get();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Repository::query()
            ->with(['project', 'branches'])
            ->orderBy('full_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function count(): int
    {
        return Repository::query()->count();
    }

    public function findById(int $id): ?Repository
    {
        return Repository::query()
            ->with(['project', 'branches'])
            ->find($id);
    }

    public function create(array $data): Repository
    {
        return Repository::query()->create($data);
    }

    public function update(Repository $repository, array $data): Repository
    {
        $repository->update($data);

        return $repository->fresh(['project', 'branches']);
    }

    public function delete(Repository $repository): bool
    {
        return (bool) $repository->delete();
    }
}
