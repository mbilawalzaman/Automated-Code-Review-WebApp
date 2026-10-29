<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RepositoryStatus;
use App\Http\Requests\Admin\Repository\StoreRepositoryRequest;
use App\Http\Requests\Admin\Repository\UpdateRepositoryRequest;
use App\Models\Repository;
use App\Services\Admin\ProjectService;
use App\Services\Admin\RepositoryActivationPreviewService;
use App\Services\Admin\RepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class RepositoryController extends AdminController
{
    public function __construct(
        private readonly RepositoryService $repositoryService,
        private readonly ProjectService $projectService,
        private readonly RepositoryActivationPreviewService $activationPreviewService,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.repositories.index', [
            'repositories' => $this->repositoryService->paginate($this->perPage($request)),
            'projects' => $this->projectService->list(),
            'statuses' => RepositoryStatus::cases(),
            'discoveryAvailable' => $this->repositoryService->discoveryAvailable(),
            'discoverUrl' => route('admin.repositories.discover'),
        ]);
    }

    public function discover(): JsonResponse
    {
        return response()->json($this->repositoryService->discoverRepositories());
    }

    public function data(): JsonResponse
    {
        return DataTables::eloquent(Repository::query()->with(['project', 'branches'])->latest())
            ->filterColumn('repository_formatted', function ($query, $keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('full_name', 'like', "%{$keyword}%")
                      ->orWhere('name', 'like', "%{$keyword}%")
                      ->orWhere('trello_board_id', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('default_branch_formatted', function ($query, $keyword) {
                $query->where('default_branch', 'like', "%{$keyword}%");
            })
            ->filterColumn('project', function ($query, $keyword) {
                $query->whereHas('project', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->addColumn('repository_formatted', function ($repository) {
                $html = '<div class="truncate font-semibold text-gray-900 text-sm" title="'.e($repository->full_name).'">'.e($repository->full_name).'</div>';
                if ($repository->trello_board_id) {
                    $html .= '<div class="truncate text-xs text-gray-500 mt-0.5" title="'.e($repository->trello_board_id).'">Trello: '.e($repository->trello_board_id).'</div>';
                }

                return $html;
            })
            ->addColumn('default_branch_formatted', function ($repository) {
                $branch = $repository->default_branch ?: '—';

                return '<div class="truncate font-mono text-sm text-gray-700" title="'.e($branch).'">'.e($branch).'</div>';
            })
            ->addColumn('project', function ($repository) {
                $name = $repository->project?->name ?? 'N/A';

                return '<div class="truncate text-sm text-gray-700" title="'.e($name).'">'.e($name).'</div>';
            })
            ->addColumn('branches_formatted', function ($repository) {
                $html = '<div class="flex flex-wrap gap-1.5">';
                if ($repository->branches->isEmpty()) {
                    $html .= '<span class="text-sm text-gray-500">No branches</span>';
                } else {
                    foreach ($repository->branches as $branch) {
                        $prot = $branch->is_protected ? ' / protected' : '';
                        $html .= '<span class="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700">'.e($branch->name.$prot).'</span>';
                    }
                }
                $html .= '</div>';

                return $html;
            })
            ->addColumn('status_formatted', function ($repository) {
                $color = $repository->is_paused ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-700';

                return '<span class="inline-flex rounded-full px-2 py-1 text-xs font-medium '.$color.'">'.e($repository->status->label()).'</span>';
            })
            ->addColumn('actions', function ($repository) {
                return view('admin.repositories.partials.actions', [
                    'repository' => $repository,
                    'activationPreview' => $this->activationPreviewService->preview($repository),
                ])->render();
            })
            ->rawColumns(['repository_formatted', 'default_branch_formatted', 'project', 'branches_formatted', 'status_formatted', 'actions'])
            ->toJson();
    }

    public function create(): View
    {
        return view('admin.repositories.create', [
            'projects' => $this->projectService->list(),
            'statuses' => RepositoryStatus::cases(),
        ]);
    }

    public function store(StoreRepositoryRequest $request): RedirectResponse
    {
        $this->repositoryService->create($request->validated());

        return redirect()
            ->route('admin.repositories.index')
            ->with('status', 'Repository created successfully.');
    }

    public function edit(int $repository): View
    {
        return view('admin.repositories.edit', [
            'repository' => $this->repositoryService->findOrFail($repository),
            'projects' => $this->projectService->list(),
            'statuses' => RepositoryStatus::cases(),
        ]);
    }

    public function update(UpdateRepositoryRequest $request, int $repository): RedirectResponse
    {
        $this->repositoryService->update($repository, $request->validated());

        return redirect()
            ->route('admin.repositories.index')
            ->with('status', 'Repository updated successfully.');
    }

    public function destroy(int $repository): RedirectResponse
    {
        $this->repositoryService->delete($repository);

        return redirect()
            ->route('admin.repositories.index')
            ->with('status', 'Repository deleted successfully.');
    }

    public function connect(int $repository): RedirectResponse
    {
        $this->repositoryService->connect($repository);

        return back()->with('status', 'Repository marked as connected.');
    }

    public function activate(int $repository): RedirectResponse
    {
        $this->repositoryService->activate($repository);
        $preview = $this->repositoryService->activationPreview($repository);
        $branches = implode(', ', $preview['branch_protection']['branches'] ?? []);

        return back()->with(
            'status',
            "Repository activated. Baseline review queued. Branch protection requested for: {$branches}."
        );
    }

    public function pause(int $repository): RedirectResponse
    {
        $this->repositoryService->pause($repository);

        return back()->with('status', 'Repository paused.');
    }

    public function resume(int $repository): RedirectResponse
    {
        $this->repositoryService->resume($repository);

        return back()->with('status', 'Repository resumed.');
    }

    public function reviewNow(Request $request, int $repository): RedirectResponse
    {
        $validated = $request->validate([
            'branch' => ['nullable', 'string', 'max:255'],
        ]);

        $job = $this->repositoryService->reviewNow(
            $repository,
            $validated['branch'] ?? null,
        );

        return back()->with('status', "Review Now queued (job #{$job->id}).");
    }

    public function protectBranches(int $repository): RedirectResponse
    {
        try {
            $result = $this->repositoryService->protectBranches($repository);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $applied = $result['applied'] !== []
            ? implode(', ', $result['applied'])
            : 'none';

        return back()->with(
            'status',
            "Branch protection applied for: {$applied}."
        );
    }
}
