<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewStatus;
use App\Http\Requests\Admin\Review\OverrideReviewRequest;
use App\Models\Repository;
use App\Models\Review;
use App\Services\Admin\ReviewService;
use App\Services\Api\RedactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Yajra\DataTables\Facades\DataTables;

class ReviewController extends AdminController
{
    public function __construct(
        private readonly ReviewService $reviewService,
        private readonly RedactionService $redactionService,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.reviews.index', [
            'reviews' => $this->reviewService->list($this->perPage($request)),
            'canOverrideReviews' => $request->user()?->hasPermissionTo('reviews.override') ?? false,
            'repositories' => Repository::query()->orderBy('full_name')->get(['id', 'full_name', 'name']),
            'statuses' => ReviewStatus::cases(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $canOverride = $request->user()?->hasPermissionTo('reviews.override') ?? false;

        $query = Review::query()->with(['repository.project', 'branch'])->withCount('findings')->latest();

        if ($request->filled('repository_id')) {
            $query->where('repository_id', (int) $request->input('repository_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('branch')) {
            $branch = $request->input('branch');
            $query->whereHas('branch', fn ($q) => $q->where('name', 'like', "%{$branch}%"));
        }
        if ($request->filled('author')) {
            $query->where('author', 'like', '%'.$request->input('author').'%');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('completed_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('completed_at', '<=', $request->input('date_to'));
        }

        $dataTable = DataTables::eloquent($query)
            ->filterColumn('review_formatted', function ($query, $keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('pull_request_title', 'like', "%{$keyword}%")
                      ->orWhere('pull_request_number', 'like', "%{$keyword}%")
                      ->orWhere('commit_sha', 'like', "%{$keyword}%")
                      ->orWhere('summary', 'like', "%{$keyword}%")
                      ->orWhere('author', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('repository_formatted', function ($query, $keyword) {
                $query->whereHas('repository', function ($q) use ($keyword) {
                    $q->where('full_name', 'like', "%{$keyword}%")
                      ->orWhere('name', 'like', "%{$keyword}%");
                })->orWhereHas('repository.project', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('branch', function ($query, $keyword) {
                $query->whereHas('branch', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->addColumn('review_formatted', function ($review) {
                $prTitle = e($review->pull_request_title ?? $review->summary);
                $sha = $review->commit_sha ? e(substr($review->commit_sha, 0, 10)) : 'No commit';
                $author = e($review->author ?? 'Unknown');
                $prNum = $review->pull_request_number ? 'PR #' . e($review->pull_request_number) : 'Review #' . e($review->id);

                return '<button type="button" class="text-left hover:underline block" data-review-detail="'.$review->id.'">' .
                       '<div class="font-semibold text-gray-900 text-sm">' . $prNum . '<span class="font-normal text-gray-600 ml-1.5">— ' . $prTitle . '</span></div>' .
                       '<div class="mt-0.5 font-mono text-xs text-gray-500">' . $sha . ' · ' . $author . '</div>' .
                       '</button>';
            })
            ->addColumn('repository_formatted', function ($review) {
                return '<div>' . e($review->repository?->full_name ?? 'N/A') . '</div>' .
                       '<div class="text-xs text-gray-500">' . e($review->repository?->project?->name ?? 'N/A') . '</div>';
            })
            ->addColumn('branch', function ($review) {
                return e($review->branch?->name ?? 'N/A');
            })
            ->addColumn('findings_count', function ($review) {
                return (int) ($review->findings_count ?? 0);
            })
            ->addColumn('status_formatted', function ($review) {
                $color = match ($review->status) {
                    ReviewStatus::Failed => 'bg-red-100 text-red-800',
                    ReviewStatus::Passed => 'bg-green-100 text-green-800',
                    default => 'bg-yellow-100 text-yellow-800',
                };
                $badge = '<span class="inline-flex rounded-full px-2 py-1 text-xs font-medium ' . $color . '">' . e($review->status->label()) . '</span>';
                if ($review->overridden_at) {
                    $badge .= ' <span class="ml-1 inline-flex rounded-full bg-purple-100 px-2 py-1 text-xs font-medium text-purple-800" title="' . e($review->override_reason ?? '') . '">Overridden</span>';
                }

                return $badge;
            })
            ->addColumn('completed_at_formatted', function ($review) {
                return e($review->completed_at?->format('M d, Y H:i') ?? 'In progress');
            });

        $rawColumns = ['review_formatted', 'repository_formatted', 'status_formatted', 'actions'];

        $dataTable->addColumn('actions', function ($review) use ($canOverride) {
            return view('admin.reviews.partials.actions', [
                'review' => $review,
                'canOverride' => $canOverride,
            ])->render();
        });

        return $dataTable->rawColumns($rawColumns)->toJson();
    }

    public function show(Review $review): JsonResponse
    {
        $review->load(['repository.project', 'branch', 'findings.objective', 'reviewJob']);

        return response()->json([
            'id' => $review->id,
            'status' => $review->status->label(),
            'status_value' => $review->status->value,
            'summary' => $this->redactionService->redact($review->summary),
            'author' => $review->author,
            'commit_sha' => $review->commit_sha,
            'pull_request_number' => $review->pull_request_number,
            'pull_request_title' => $review->pull_request_title,
            'repository' => $review->repository?->full_name,
            'project' => $review->repository?->project?->name,
            'branch' => $review->branch?->name,
            'tools_used' => $review->tools_used ?? [],
            'started_at' => $review->started_at?->toIso8601String(),
            'completed_at' => $review->completed_at?->toIso8601String(),
            'overridden_at' => $review->overridden_at?->toIso8601String(),
            'override_reason' => $review->override_reason,
            'change_classes' => $review->change_classes ?? [],
            'impact_analysis' => $review->impact_analysis ?? [],
            'tool_executions' => $review->tool_executions ?? [],
            'findings' => $review->findings->map(fn ($finding) => [
                'id' => $finding->id,
                'title' => $finding->title,
                'severity' => $finding->severity->label(),
                'status' => $finding->status->value,
                'file_path' => $finding->file_path,
                'line_number' => $finding->line_number,
                'evidence' => $this->redactionService->redact($finding->evidence),
                'recommendation' => $this->redactionService->redact($finding->recommendation),
                'objective' => $finding->objective?->code,
                'fingerprint' => $finding->fingerprint,
                'is_blocking' => $finding->is_blocking,
            ]),
        ]);
    }

    public function override(OverrideReviewRequest $request, Review $review): RedirectResponse
    {
        try {
            $this->reviewService->override(
                $review,
                $request->validated('reason'),
                $request->user(),
                [
                    'documentation_url' => $request->validated('documentation_url'),
                    'documentation_notes' => $request->validated('documentation_notes'),
                ],
            );

            return back()->with('status', 'Review override recorded.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
