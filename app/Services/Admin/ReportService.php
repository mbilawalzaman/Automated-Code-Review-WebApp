<?php

namespace App\Services\Admin;

use App\Models\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReportService
{
    /**
     * @return LengthAwarePaginator<int, Report>
     */
    public function list(int $perPage = 10): LengthAwarePaginator
    {
        return Report::query()
            ->with(['project', 'repository', 'review', 'generator'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }
}
