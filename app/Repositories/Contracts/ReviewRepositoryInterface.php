<?php

namespace App\Repositories\Contracts;

use App\Models\Review;

interface ReviewRepositoryInterface
{
    public function count(): int;

    public function findById(int $id): ?Review;

    public function create(array $data): Review;
}
