<?php

namespace App\Repositories\Contracts;

use App\Models\Finding;

interface FindingRepositoryInterface
{
    public function count(): int;

    public function create(array $data): Finding;

    public function createMany(array $findings): array;
}
