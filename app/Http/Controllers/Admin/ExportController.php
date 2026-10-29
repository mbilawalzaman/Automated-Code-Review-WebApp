<?php

namespace App\Http\Controllers\Admin;

use App\Models\Finding;
use App\Models\Review;
use App\Services\Api\RedactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends AdminController
{
    /**
     * Rows per export, matching the audit log export ceiling.
     */
    protected const MAX_ROWS = 5000;

    public function __construct(
        protected RedactionService $redactionService,
    ) {}

    /**
     * Review history export (UI-005). Honours the same filters as the review list.
     */
    public function reviews(Request $request): StreamedResponse
    {
        $reviews = $this->applyCommonFilters(
            Review::query()->with(['repository.project', 'branch'])->withCount('findings'),
            $request
        )
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->latest()
            ->limit(self::MAX_ROWS)
            ->get();

        $rows = $reviews->map(fn (Review $review) => [
            'id' => $review->id,
            'project' => $review->repository?->project?->name,
            'repository' => $review->repository?->full_name,
            'branch' => $review->branch?->name,
            'commit_sha' => $review->commit_sha,
            'pull_request_number' => $review->pull_request_number,
            'status' => $review->status instanceof \BackedEnum ? $review->status->value : (string) $review->status,
            'author' => $review->author,
            'findings_count' => $review->findings_count,
            'summary' => $this->redactionService->redact($review->summary),
            'started_at' => $review->started_at?->toIso8601String(),
            'completed_at' => $review->completed_at?->toIso8601String(),
        ])->all();

        return $this->stream($request, $rows, 'reviews');
    }

    /**
     * Findings export (UI-005), including the objective each finding maps to (FND-001).
     */
    public function findings(Request $request): StreamedResponse
    {
        $findings = $this->applyCommonFilters(
            Finding::query()->with(['review.repository.project', 'objective']),
            $request,
            'review.'
        )
            ->when($request->filled('severity'), fn (Builder $query) => $query->where('severity', $request->string('severity')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->latest()
            ->limit(self::MAX_ROWS)
            ->get();

        $rows = $findings->map(fn (Finding $finding) => [
            'id' => $finding->id,
            'review_id' => $finding->review_id,
            'project' => $finding->review?->repository?->project?->name,
            'repository' => $finding->review?->repository?->full_name,
            'severity' => $finding->severity instanceof \BackedEnum ? $finding->severity->value : (string) $finding->severity,
            'status' => $finding->status instanceof \BackedEnum ? $finding->status->value : (string) $finding->status,
            'is_blocking' => $finding->is_blocking ? 'yes' : 'no',
            'objective_code' => $finding->objective?->code,
            'objective_title' => $finding->objective?->title,
            'objective_impact' => $this->redactionService->redact($finding->objective_impact),
            'file_path' => $finding->file_path,
            'line_number' => $finding->line_number,
            'rule_id' => $finding->rule_id,
            'source' => $finding->source,
            'title' => $finding->title,
            'recommendation' => $this->redactionService->redact($finding->recommendation),
            'created_at' => $finding->created_at?->toIso8601String(),
        ])->all();

        return $this->stream($request, $rows, 'findings');
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    protected function applyCommonFilters(Builder $query, Request $request, string $relationPrefix = ''): Builder
    {
        $repositoryColumn = $relationPrefix === '' ? 'repository_id' : null;

        return $query
            ->when($request->filled('repository_id'), function (Builder $q) use ($request, $repositoryColumn) {
                $repositoryId = $request->integer('repository_id');

                $repositoryColumn
                    ? $q->where($repositoryColumn, $repositoryId)
                    : $q->whereHas('review', fn (Builder $review) => $review->where('repository_id', $repositoryId));
            })
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date('to')));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function stream(Request $request, array $rows, string $resource): StreamedResponse
    {
        $format = strtolower((string) $request->input('format', 'csv')) === 'json' ? 'json' : 'csv';
        $filename = "{$resource}_" . date('Y_m_d_His') . ".{$format}";

        if ($format === 'json') {
            return response()->streamDownload(function () use ($rows) {
                echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }, $filename, ['Content-Type' => 'application/json']);
        }

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            if ($rows === []) {
                fclose($handle);

                return;
            }

            fputcsv($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value),
                    $row
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
