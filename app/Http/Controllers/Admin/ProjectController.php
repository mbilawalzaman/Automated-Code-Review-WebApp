<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Project\StoreProjectRequest;
use App\Http\Requests\Admin\Project\UpdateProjectRequest;
use App\Models\Project;
use App\Services\Admin\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class ProjectController extends AdminController
{
    public function __construct(
        private readonly ProjectService $projectService,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.projects.index', [
            'projects' => $this->projectService->paginate($this->perPage($request)),
        ]);
    }

    public function data(): JsonResponse
    {
        return DataTables::eloquent(Project::query()->withCount('repositories'))
            ->filterColumn('name', function ($query, $keyword) {
                $query->where('name', 'like', "%{$keyword}%");
            })
            ->filterColumn('slug', function ($query, $keyword) {
                $query->where('slug', 'like', "%{$keyword}%");
            })
            ->filterColumn('description', function ($query, $keyword) {
                $query->where('description', 'like', "%{$keyword}%");
            })
            ->orderColumn('repositories_count', 'repositories_count $1')
            ->orderColumn('is_active', 'is_active $1')
            ->editColumn('name', function ($project) {
                return '<div class="truncate font-medium text-gray-900" title="'.e($project->name).'">'.e($project->name).'</div>';
            })
            ->editColumn('slug', function ($project) {
                return '<div class="truncate font-mono text-sm text-gray-500" title="'.e($project->slug).'">'.e($project->slug).'</div>';
            })
            ->editColumn('description', function ($project) {
                if (! $project->description) {
                    return '<span class="text-sm text-gray-400">—</span>';
                }

                $text = e($project->description);

                return '<div class="truncate text-sm text-gray-600" title="'.$text.'">'.$text.'</div>';
            })
            ->addColumn('repositories_count', function ($project) {
                return $project->repositories_count;
            })
            ->addColumn('status_formatted', function ($project) {
                $color = $project->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700';
                $label = $project->is_active ? 'Active' : 'Inactive';

                return '<span class="inline-flex rounded-full px-2 py-1 text-xs font-medium '.$color.'">'.e($label).'</span>';
            })
            ->addColumn('actions', function ($project) {
                return view('admin.projects.partials.actions', compact('project'))->render();
            })
            ->rawColumns(['name', 'slug', 'description', 'status_formatted', 'actions'])
            ->toJson();
    }

    public function create(): View
    {
        return view('admin.projects.create');
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $this->projectService->create($request->validated());

        return redirect()
            ->route('admin.projects.index')
            ->with('status', 'Project created successfully.');
    }

    public function edit(int $project): View
    {
        return view('admin.projects.edit', [
            'project' => $this->projectService->findOrFail($project),
        ]);
    }

    public function update(UpdateProjectRequest $request, int $project): RedirectResponse
    {
        $this->projectService->update($project, $request->validated());

        return redirect()
            ->route('admin.projects.index')
            ->with('status', 'Project updated successfully.');
    }

    public function destroy(int $project): RedirectResponse
    {
        $this->projectService->delete($project);

        return redirect()
            ->route('admin.projects.index')
            ->with('status', 'Project deleted successfully.');
    }
}
