<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SrsDocumentStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\SrsDocument;
use App\Services\Admin\SrsLifecycleService;
use App\Services\Admin\SrsSourceAdapterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SrsDocumentController extends AdminController
{
    public function __construct(
        private readonly SrsLifecycleService $lifecycle,
        private readonly SrsSourceAdapterService $sourceAdapter,
    ) {}

    public function index(Request $request): View
    {
        $documents = SrsDocument::query()
            ->with(['project', 'repository', 'approver'])
            ->withCount('objectives')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            // A repository is governed by its own revisions plus the project-wide ones,
            // so this filter answers the same question the review pipeline asks.
            ->when($request->filled('repository_id'), function ($query) use ($request) {
                $repository = Repository::query()->find($request->integer('repository_id'));

                $repository
                    ? $query->forRepositoryOrProject($repository)
                    : $query->whereRaw('1 = 0');
            })
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return view('admin.srs.index', [
            'documents' => $documents,
            'projects' => Project::query()->orderBy('name')->get(),
            'repositories' => Repository::query()
                ->orderBy('full_name')
                ->get(['id', 'project_id', 'full_name'])
                ->map(fn (Repository $repository) => [
                    'id' => (string) $repository->id,
                    'project_id' => (string) $repository->project_id,
                    'full_name' => $repository->full_name,
                ])
                ->values(),
            'availableSources' => $this->sourceAdapter->availableSources(),
            'statuses' => SrsDocumentStatus::cases(),
        ]);
    }

    public function show(SrsDocument $srsDocument): View
    {
        $srsDocument->load(['project', 'repository', 'approver', 'rejecter']);

        return view('admin.srs.show', [
            'document' => $srsDocument,
            'objectives' => $srsDocument->objectives()->orderBy('sort_order')->orderBy('id')->get(),
            'availableSources' => $this->sourceAdapter->availableSources(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', 'string', 'in:' . implode(',', $this->sourceAdapter->availableSources())],
            'title' => ['required', 'string', 'max:255'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'repository_id' => ['nullable', 'integer', 'exists:repositories,id'],
            'document' => ['nullable', 'file', 'max:10240'],
            'reference' => ['nullable', 'string', 'max:2048'],
            'git_ref' => ['nullable', 'string', 'max:255'],
        ]);

        $repository = isset($validated['repository_id'])
            ? Repository::query()->find($validated['repository_id'])
            : null;

        if ($repository && (int) $repository->project_id !== (int) $validated['project_id']) {
            return back()
                ->withInput()
                ->withErrors(['repository_id' => 'The selected repository does not belong to the chosen project.']);
        }

        $projectId = (int) $validated['project_id'];

        try {
            $document = $this->lifecycle->createRevision(
                sourceType: $validated['source_type'],
                repository: $repository,
                projectId: $projectId,
                title: $validated['title'],
                file: $request->file('document'),
                reference: $validated['reference'] ?? null,
                gitRef: $validated['git_ref'] ?? null,
            );
        } catch (\Throwable $e) {
            // Intake failures are almost always about the file or link the user
            // supplied, so surface them on that field rather than the source picker.
            $field = $validated['source_type'] === SrsSourceAdapterService::SOURCE_UPLOAD
                ? 'document'
                : 'reference';

            $message = $e instanceof \Illuminate\Database\QueryException
                ? 'This document could not be saved. Use a requirements document (Markdown, TXT, PDF, or DOCX up to 10 MB) with readable text.'
                : $e->getMessage();

            return back()->withInput()->withErrors([$field => $message]);
        }

        return redirect()
            ->route('admin.srs.show', $document)
            ->with('status', "Revision v{$document->version} created with {$document->objectives()->count()} extracted requirements. Review the checklist and submit it for approval.");
    }

    public function replace(Request $request, SrsDocument $srsDocument): RedirectResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', 'string', 'in:' . implode(',', $this->sourceAdapter->availableSources())],
            'title' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'file', 'max:10240'],
            'reference' => ['nullable', 'string', 'max:2048'],
            'git_ref' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->lifecycle->replaceDocument(
                document: $srsDocument,
                sourceType: $validated['source_type'],
                file: $request->file('document'),
                reference: $validated['reference'] ?? null,
                gitRef: $validated['git_ref'] ?? null,
                title: $validated['title'] ?? null,
            );
        } catch (\Throwable $e) {
            $field = $validated['source_type'] === SrsSourceAdapterService::SOURCE_UPLOAD
                ? 'document'
                : 'reference';

            $message = $e instanceof \Illuminate\Database\QueryException
                ? 'This document could not be saved. Use a requirements document (Markdown, TXT, PDF, or DOCX up to 10 MB) with readable text.'
                : $e->getMessage();

            return back()->withInput()->withErrors([$field => $message]);
        }

        return back()->with(
            'status',
            "Document replaced. Checklist re-extracted with {$srsDocument->fresh()->objectives()->count()} requirements."
        );
    }

    public function destroy(SrsDocument $srsDocument): RedirectResponse
    {
        try {
            $this->lifecycle->deleteRevision($srsDocument);
        } catch (\Throwable $e) {
            return back()->withErrors(['checklist' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.srs.index')
            ->with('status', 'SRS revision deleted.');
    }

    public function extract(SrsDocument $srsDocument): RedirectResponse
    {
        try {
            $this->lifecycle->reExtractChecklist($srsDocument);
        } catch (\Throwable $e) {
            return back()->withErrors(['checklist' => $e->getMessage()]);
        }

        return back()->with('status', 'Checklist re-extracted from the stored document.');
    }

    public function updateChecklist(Request $request, SrsDocument $srsDocument): RedirectResponse
    {
        $validated = $request->validate(
            [
                'items' => ['array'],
                'items.*.id' => ['nullable', 'integer'],
                'items.*.code' => ['nullable', 'string', 'max:64'],
                'items.*.title' => ['nullable', 'string', 'max:255'],
                'items.*.description' => ['nullable', 'string', 'max:2000'],
                'items.*.is_mandatory' => ['nullable', 'boolean'],
                'items.*.remove' => ['nullable', 'boolean'],
            ],
            [
                'items.*.title.max' => 'Requirement text must be 255 characters or fewer.',
                'items.*.description.max' => 'Requirement description must be 2,000 characters or fewer.',
                'items.*.code.max' => 'Requirement code must be 64 characters or fewer.',
            ],
            [
                'items.*.title' => 'requirement text',
                'items.*.description' => 'requirement description',
                'items.*.code' => 'requirement code',
            ]
        );

        try {
            $this->lifecycle->updateChecklist($srsDocument, $validated['items'] ?? []);
        } catch (\Throwable $e) {
            return back()->withErrors(['checklist' => $e->getMessage()]);
        }

        return back()->with('status', 'Checklist updated.');
    }

    public function submit(SrsDocument $srsDocument): RedirectResponse
    {
        try {
            $this->lifecycle->submitForApproval($srsDocument);
        } catch (\Throwable $e) {
            return back()->withErrors(['checklist' => $e->getMessage()]);
        }

        return back()->with('status', 'Revision submitted for Project Manager approval.');
    }

    public function approve(Request $request, SrsDocument $srsDocument): RedirectResponse
    {
        try {
            $this->lifecycle->approve($srsDocument, $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('status', 'Revision approved. Objective-aware reviews now use this checklist.');
    }

    public function reject(Request $request, SrsDocument $srsDocument): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $this->lifecycle->reject($srsDocument, $request->user(), $validated['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('status', 'Revision rejected and returned for revision.');
    }
}
