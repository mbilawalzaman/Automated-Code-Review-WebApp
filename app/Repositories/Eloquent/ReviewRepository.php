<?php

namespace App\Repositories\Eloquent;

use App\Models\Review;
use App\Repositories\Contracts\ReviewRepositoryInterface;

class ReviewRepository implements ReviewRepositoryInterface
{
    public function count(): int
    {
        return Review::query()->count();
    }

    public function findById(int $id): ?Review
    {
        return Review::query()->find($id);
    }

    public function create(array $data): Review
    {
        return Review::query()->create($data);
    }
}
