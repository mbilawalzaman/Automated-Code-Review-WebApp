@extends('layouts.admin')

@section('title', 'Objectives & SRS')
@section('page-title', 'Objectives & SRS')

@section('content')
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Objective & SRS Management</h2>
            <p class="mt-1 text-sm text-gray-600">
                Upload or link requirement documents, curate the extracted checklist, and approve the revision that governs
                objective-aware reviews. Repositories without an approved revision receive technical and security review only.
            </p>
        </div>

        @can('srs.manage')
            <button
                type="button"
                class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 shrink-0 self-start sm:self-auto"
                x-data
                x-on:click="$dispatch('open-modal', 'srs-create')"
            >
                Add SRS Revision
            </button>
        @endcan
    </div>

    <div class="rounded-lg bg-white shadow overflow-hidden">
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-gray-200 px-6 py-4">
            <div>
                <label for="filter-status" class="block text-xs font-medium uppercase tracking-wide text-gray-500">Status</label>
                <select id="filter-status" name="status" class="mt-1 rounded-md border-gray-300 text-sm"
                onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-project" class="block text-xs font-medium uppercase tracking-wide text-gray-500">Project</label>
                <select id="filter-project" name="project_id"
                class="mt-1 rounded-md border-gray-300 text-sm"
                onchange="this.form.submit()">
                    <option value="">All projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>
                            {{ $project->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </form>

        <div class="overflow-x-auto min-w-full">
            <table class="w-full table-fixed divide-y divide-gray-200 text-left text-sm min-w-[900px]">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="w-[22%] px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Document</th>
                        <th scope="col" class="w-[10%] px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Revision</th>
                        <th scope="col" class="w-[26%] px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Scope</th>
                        <th scope="col" class="w-[12%] px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Source</th>
                        <th scope="col" class="w-[10%] px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Requirements</th>
                        <th scope="col" class="w-[12%] px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th scope="col" class="w-[8%] px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($documents as $document)
                        <tr class="hover:bg-gray-50/50">
                            <td class="px-6 py-4">
                                <div class="truncate font-medium text-gray-900" title="{{ $document->title }}">{{ $document->title }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-mono text-sm text-gray-700">v{{ $document->version }}</div>
                            </td>
                            <td class="px-6 py-4 text-gray-600">
                                <div class="truncate" title="{{ $document->repository?->full_name ?? ($document->project?->name . ' (project-wide)') }}">
                                    {{ $document->repository?->full_name ?? ($document->project?->name . ' (project-wide)') }}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-600">
                                <div class="truncate">{{ $document->sourceLabel() }}</div>
                            </td>
                            <td class="px-6 py-4 text-gray-900 font-medium">{{ $document->objectives_count }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $document->status->badgeClass() }}">
                                    {{ $document->status->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                @php
                                    $canDeleteSrs = auth()->user()?->can('srs.manage') && $document->isDeletable();
                                @endphp
                                @if ($canDeleteSrs)
                                    <x-admin.actions-dropdown>
                                        <a href="{{ route('admin.srs.show', $document) }}"
                                           class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">
                                            Open
                                        </a>
                                        <button type="button"
                                                class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50"
                                                x-on:click="$dispatch('open-modal', 'srs-delete-{{ $document->id }}'); open = false">
                                            Delete
                                        </button>
                                    </x-admin.actions-dropdown>
                                @else
                                    <a href="{{ route('admin.srs.show', $document) }}" class="text-sm font-medium text-gray-900 hover:underline">
                                        Open
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                                No SRS revisions yet. Add one to enable objective-aware reviews.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-admin.pagination-footer :paginator="$documents" label="revisions" />
    </div>

    @can('srs.manage')
        @foreach ($documents as $document)
            @if ($document->isDeletable())
                <x-modal name="srs-delete-{{ $document->id }}" maxWidth="md">
                    <div class="p-6">
                        <h3 class="text-base font-semibold text-gray-900">Delete SRS revision</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Delete <span class="font-medium text-gray-900">{{ $document->title }} — v{{ $document->version }}</span>?
                            This removes the revision and its checklist.
                        </p>

                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button" x-on:click="$dispatch('close-modal', 'srs-delete-{{ $document->id }}')"
                                    class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                Cancel
                            </button>
                            <form method="POST" action="{{ route('admin.srs.destroy', $document) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </x-modal>
            @endif
        @endforeach
    @endcan

    @can('srs.manage')
        <x-modal name="srs-create" :show="$errors->any()" maxWidth="2xl" focusable>
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-base font-semibold text-gray-900">Add SRS Revision</h3>
                <p class="mt-1 text-sm text-gray-600">
                    Provide the requirements document. Requirements are extracted automatically and remain inactive until approved.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('admin.srs.store') }}"
                enctype="multipart/form-data"
                class="space-y-5 p-6"
                x-data="srsCreateForm({
                    source: @js(old('source_type', 'upload')),
                    projectId: @js((string) (old('project_id') ?? '')),
                    repositoryId: @js((string) (old('repository_id') ?? '')),
                    repositories: @js($repositories),
                    documentError: @js($errors->first('document')),
                })"
            >
                @csrf

                <div>
                    <label for="srs-title" class="block text-sm font-medium text-gray-700">Document title</label>
                    <input id="srs-title" name="title" type="text" value="{{ old('title') }}" required
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm @error('title') border-red-400 @enderror"
                           placeholder="Project SRS v1">
                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="srs-project" class="block text-sm font-medium text-gray-700">Project</label>
                        <select id="srs-project" name="project_id" required x-model="projectId" x-on:change="onProjectChange()"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm @error('project_id') border-red-400 @enderror">
                            <option value="">Select project</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>
                                    {{ $project->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Choose the project this SRS governs.</p>
                        <x-input-error :messages="$errors->get('project_id')" class="mt-1" />
                    </div>

                    <div>
                        <label for="srs-repository" class="block text-sm font-medium text-gray-700">Repository</label>
                        <select id="srs-repository" name="repository_id" x-model="repositoryId"
                                x-bind:disabled="! projectId"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm disabled:bg-gray-100 disabled:text-gray-500 @error('repository_id') border-red-400 @enderror">
                            <option value="">Project-wide (all repositories)</option>
                            <template x-for="repository in filteredRepositories" :key="repository.id">
                                <option :value="repository.id" x-text="repository.full_name"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-gray-500" x-show="! projectId">Select a project first.</p>
                        <p class="mt-1 text-xs text-gray-500" x-show="projectId" x-cloak>
                            Leave as project-wide, or pick one repository in this project. A repository-specific revision overrides the project-wide one.
                        </p>
                        <x-input-error :messages="$errors->get('repository_id')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <label for="srs-source" class="block text-sm font-medium text-gray-700">Source</label>
                    <select id="srs-source" name="source_type" x-model="source" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        @foreach ($availableSources as $source)
                            <option value="{{ $source }}">
                                {{ ['upload' => 'Upload document', 'github' => 'GitHub file', 'google_drive' => 'Google Drive file'][$source] ?? $source }}
                            </option>
                        @endforeach
                    </select>
                    @unless (in_array('google_drive', $availableSources, true))
                        <p class="mt-1 text-xs text-gray-500">Google Drive appears once GOOGLE_DRIVE_* credentials are configured.</p>
                    @endunless
                    <x-input-error :messages="$errors->get('source_type')" class="mt-1" />
                </div>

                <div x-show="source === 'upload'" x-cloak>
                    <label for="srs-document" class="block text-sm font-medium text-gray-700">Document file</label>
                    <input id="srs-document" name="document" type="file" accept=".md,.markdown,.txt,.text,.pdf,.docx"
                           x-on:change="onDocumentChange($event)"
                           x-bind:class="documentError ? 'border-red-400' : 'border-gray-300'"
                           class="mt-1 block w-full rounded-md border p-2 text-sm">
                    <p class="mt-1 text-xs text-gray-500">Markdown, TXT, PDF, or DOCX up to 10 MB.</p>
                    <p x-show="documentError" x-cloak x-text="documentError" class="mt-1 text-sm text-red-600"></p>
                </div>

                <div x-show="source !== 'upload'" x-cloak class="space-y-4">
                    <div>
                        <label for="srs-reference" class="block text-sm font-medium text-gray-700">
                            <span x-show="source === 'github'">Repository path or GitHub file URL</span>
                            <span x-show="source === 'google_drive'">Drive file ID or sharing link</span>
                        </label>
                        <input id="srs-reference" name="reference" type="text" value="{{ old('reference') }}"
                               class="mt-1 block w-full rounded-md border-gray-300 text-sm @error('reference') border-red-400 @enderror"
                               x-bind:placeholder="source === 'google_drive' ? 'https://drive.google.com/file/d/FILE_ID/view' : 'docs/SRS.md'">
                        <p class="mt-1 text-xs text-gray-500" x-show="source === 'google_drive'" x-cloak>
                            Paste the document link (file/d/…), not the folder. The folder in GOOGLE_DRIVE_FOLDER_ID must be shared with the service account.
                        </p>
                        <x-input-error :messages="$errors->get('reference')" class="mt-1" />
                    </div>

                    <div x-show="source === 'github'" x-cloak>
                        <label for="srs-git-ref" class="block text-sm font-medium text-gray-700">Branch or tag (optional)</label>
                        <input id="srs-git-ref" name="git_ref" type="text" value="{{ old('git_ref') }}"
                               class="mt-1 block w-full rounded-md border-gray-300 text-sm @error('git_ref') border-red-400 @enderror"
                               placeholder="main">
                        <x-input-error :messages="$errors->get('git_ref')" class="mt-1" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-5">
                    <button type="button" x-on:click="$dispatch('close-modal', 'srs-create')"
                            class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                        Cancel
                    </button>
                    <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                        Create Revision
                    </button>
                </div>
            </form>
        </x-modal>
    @endcan
@endsection

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('srsCreateForm', ({ source, projectId, repositoryId, repositories, documentError }) => ({
            source: source || 'upload',
            projectId: projectId || '',
            repositoryId: repositoryId || '',
            repositories: Array.isArray(repositories) ? repositories : [],
            documentError: documentError || '',
            allowedExtensions: ['md', 'markdown', 'txt', 'text', 'pdf', 'docx'],

            get filteredRepositories() {
                if (! this.projectId) {
                    return [];
                }

                return this.repositories.filter((repository) => String(repository.project_id) === String(this.projectId));
            },

            onProjectChange() {
                const stillValid = this.filteredRepositories.some(
                    (repository) => String(repository.id) === String(this.repositoryId),
                );
                if (! stillValid) {
                    this.repositoryId = '';
                }
            },

            onDocumentChange(event) {
                const file = event.target.files?.[0] ?? null;

                if (! file) {
                    this.documentError = '';

                    return;
                }

                const name = String(file.name || '');
                const extension = name.includes('.')
                    ? name.slice(name.lastIndexOf('.') + 1).toLowerCase()
                    : '';

                if (! this.allowedExtensions.includes(extension)) {
                    this.documentError = 'Unsupported file type. Upload Markdown, TXT, PDF, or DOCX.';

                    return;
                }

                // Clear stale server/client errors once a supported file is chosen.
                this.documentError = '';
            },
        }));
    });
</script>
@endpush
