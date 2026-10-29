<?php

namespace App\Repositories\Eloquent;

use App\Enums\ReviewJobStatus;
use App\Models\ReviewJob;
use App\Repositories\Contracts\ReviewJobRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ReviewJobRepository implements ReviewJobRepositoryInterface
{
    public function findById(int $id): ?ReviewJob
    {
        return ReviewJob::find($id);
    }

    public function create(array $data): ReviewJob
    {
        return ReviewJob::create($data);
    }

    public function updateStatus(ReviewJob $job, string $status, ?string $errorMessage = null): ReviewJob
    {
        $updateData = ['status' => $status];

        if ($status === ReviewJobStatus::Running->value && !$job->started_at) {
            $updateData['started_at'] = now();
        }

        if (in_array($status, [ReviewJobStatus::Completed->value, ReviewJobStatus::Failed->value], true)) {
            $updateData['completed_at'] = now();
        }

        if ($errorMessage !== null) {
            $updateData['error_message'] = $errorMessage;
        }

        $job->update($updateData);

        return $job->fresh();
    }

    public function getPendingJobs(int $limit = 10): Collection
    {
        return ReviewJob::where('status', ReviewJobStatus::Pending->value)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }
}
