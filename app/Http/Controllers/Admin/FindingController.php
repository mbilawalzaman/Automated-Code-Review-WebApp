<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Http\Requests\Admin\Finding\MarkFindingReasonRequest;
use App\Models\Finding;
use App\Models\Repository;
use App\Services\Admin\FindingService;
use App\Services\Api\RedactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Yajra\DataTables\Facades\DataTables;

class FindingController extends AdminController
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly RedactionService $redactionService,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.findings.index', [
            'findings' => $this->findingService->list($this->perPage($request)),
            'canManageFindings' => $request->user()?->hasPermissionTo('findings.manage') ?? false,
            'repositories' => Repository::query()->orderBy('full_name')->get(['id', 'full_name', 'name']),
            'severities' => FindingSeverity::cases(),
            'statuses' => FindingStatus::cases(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $canManage = $request->user()?->hasPermissionTo('findings.manage') ?? false;

        $query = Finding::query()->with(['review.repository', 'objective'])->latest();

        if ($request->filled('repository_id')) {
            $repoId = (int) $request->input('repository_id');
            $query->whereHas('review', fn ($q) => $q->where('repository_id', $repoId));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $dataTable = DataTables::eloquent($query)
            ->filterColumn('finding_formatted', function ($query, $keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('title', 'like', "%{$keyword}%")
                      ->orWhere('evidence', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('repository_formatted', function ($query, $keyword) {
                $query->whereHas('review.repository', function ($q) use ($keyword) {
                    $q->where('full_name', 'like', "%{$keyword}%")
                      ->orWhere('name', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('location_formatted', function ($query, $keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('file_path', 'like', "%{$keyword}%")
                      ->orWhere('line_number', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('objective', function ($query, $keyword) {
                $query->whereHas('objective', function ($q) use ($keyword) {
                    $q->where('code', 'like', "%{$keyword}%");
                });
            })
            ->addColumn('finding_formatted', function ($finding) {
                $evidence = e($this->redactionService->safeEvidenceSnippet($finding->evidence, 160));

                return '<button type="button" class="text-left hover:underline" data-finding-detail="'.$finding->id.'">' .
                       '<div class="font-medium text-gray-900">' . e($finding->title) . '</div>' .
                       '<div class="mt-1 max-w-xl text-sm text-gray-600">' . $evidence . '</div>' .
                       '</button>';
            })
            ->addColumn('repository_formatted', function ($finding) {
                return '<div>' . e($finding->review?->repository?->full_name ?? 'N/A') . '</div>' .
                       '<div class="text-xs text-gray-500">PR #' . e($finding->review?->pull_request_number ?? 'N/A') . '</div>';
            })
            ->addColumn('location_formatted', function ($finding) {
                return '<div class="font-mono text-xs">' . e($finding->file_path) . '</div>' .
                       '<div class="text-xs text-gray-500">Line ' . e($finding->line_number ?? 'N/A') . '</div>';
            })
            ->addColumn('objective', function ($finding) {
                return e($finding->objective?->code ?? 'N/A');
            })
            ->addColumn('severity_formatted', function ($finding) {
                $color = $finding->is_blocking ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800';
                return '<span class="inline-flex rounded-full px-2 py-1 text-xs font-medium ' . $color . '">' . e($finding->severity->label()) . '</span>';
            })
            ->addColumn('status_formatted', function ($finding) {
                $label = e(str($finding->status->value)->replace('_', ' ')->title());
                if ($finding->status === FindingStatus::FalsePositive && $finding->false_positive_reason) {
                    return '<span title="' . e($finding->false_positive_reason) . '">' . $label . '</span>';
                }

                return $label;
            });

        $rawColumns = ['finding_formatted', 'repository_formatted', 'location_formatted', 'severity_formatted', 'status_formatted'];

        if ($canManage) {
            $dataTable->addColumn('actions', function ($finding) {
                return view('admin.findings.partials.actions', [
                    'finding' => $finding,
                    'canManage' => true,
                ])->render();
            });
            $rawColumns[] = 'actions';
        }

        return $dataTable->rawColumns($rawColumns)->toJson();
    }

    public function show(Finding $finding): JsonResponse
    {
        $finding->load(['review.repository.project', 'objective', 'parentFinding', 'resolvedInReview']);

        return response()->json([
            'id' => $finding->id,
            'title' => $finding->title,
            'severity' => $finding->severity->label(),
            'status' => $finding->status->value,
            'file_path' => $finding->file_path,
            'line_number' => $finding->line_number,
            'rule_id' => $finding->rule_id,
            'fingerprint' => $finding->fingerprint,
            'source' => $finding->source,
            'evidence' => $this->redactionService->redact($finding->evidence),
            'recommendation' => $this->redactionService->redact($finding->recommendation),
            'objective_impact' => $this->redactionService->redact($finding->objective_impact),
            'objective' => $finding->objective?->code,
            'is_blocking' => $finding->is_blocking,
            'repository' => $finding->review?->repository?->full_name,
            'review_id' => $finding->review_id,
            'parent_finding_id' => $finding->parent_finding_id,
            'resolved_in_review_id' => $finding->resolved_in_review_id,
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'false_positive_reason' => $finding->false_positive_reason,
        ]);
    }

    public function markFalsePositive(MarkFindingReasonRequest $request, Finding $finding): RedirectResponse
    {
        try {
            $this->findingService->markFalsePositive(
                $finding,
                $request->validated('reason'),
                $request->user(),
            );

            return back()->with('status', 'Finding marked as false positive.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function accept(MarkFindingReasonRequest $request, Finding $finding): RedirectResponse
    {
        try {
            $this->findingService->accept(
                $finding,
                $request->validated('reason'),
                $request->user(),
            );

            return back()->with('status', 'Finding accepted as a warning.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
