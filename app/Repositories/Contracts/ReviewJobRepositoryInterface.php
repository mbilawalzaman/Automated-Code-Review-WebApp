<?php

namespace App\Repositories\Contracts;

use App\Models\ReviewJob;
use Illuminate\Database\Eloquent\Collection;

interface ReviewJobRepositoryInterface
{
    public function findById(int $id): ?ReviewJob;

    public function create(array $data): ReviewJob;

    public function updateStatus(ReviewJob $job, string $status, ?string $errorMessage = null): ReviewJob;

    public function getPendingJobs(int $limit = 10): Collection;
}
