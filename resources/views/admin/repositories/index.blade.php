@extends('layouts.admin')

@section('title', 'Repositories')
@section('page-title', 'Repositories')

@section('content')
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Repository Management</h2>
            <p class="mt-1 text-sm text-gray-600">Configure repositories for manual activation, branch review settings, Trello mapping, and review status controls.</p>
        </div>
        @can('repositories.manage')
        <button
            type="button"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 shrink-0 self-start sm:self-auto"
            x-data
            x-on:click="$dispatch('open-modal', 'repository-create')"
        >
            Add Repository
        </button>
        @endcan
    </div>

    @can('repositories.manage')
        <x-reauth-banner action="activating a repository for automated review" />
    @endcan

    <div class="rounded-lg bg-white shadow p-4 sm:p-6 overflow-hidden">
        <div class="overflow-x-auto min-w-full">
            <table
                id="repositories-table"
                data-datatable
                data-ajax-url="{{ route('admin.repositories.data') }}"
                data-columns='[
                    {"data": "repository_formatted", "name": "full_name", "width": "22%"},
                    {"data": "default_branch_formatted", "name": "default_branch", "width": "12%"},
                    {"data": "project", "name": "project.name", "width": "18%"},
                    {"data": "branches_formatted", "name": "branches", "orderable": false, "searchable": false, "width": "22%"},
                    {"data": "status_formatted", "name": "status", "width": "12%"},
                    {"data": "actions", "name": "actions", "orderable": false, "searchable": false, "width": "14%"}
                ]'
                class="w-full table-fixed divide-y divide-gray-200 min-w-[900px]"
            >
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="w-[22%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Repository</th>
                        <th scope="col" class="w-[12%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Default Branch</th>
                        <th scope="col" class="w-[18%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Project</th>
                        <th scope="col" class="w-[22%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Branches</th>
                        <th scope="col" class="w-[12%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th scope="col" class="w-[14%] px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>

        @can('repositories.manage')
        {{-- Modals --}}
        <x-modal name="repository-create" :show="old('_modal') === 'repository-create'" maxWidth="2xl" focusable>
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-base font-semibold text-gray-900">Add Repository</h3>
                <p class="mt-1 text-sm text-gray-600">Discover from the GitHub App installation, or enter a repository manually. Nothing is reviewed until connected and activated (ONB-001).</p>
            </div>

            <form
                method="POST"
                action="{{ route('admin.repositories.store') }}"
                class="space-y-6 p-6"
                x-data="repositoryDiscovery({
                    discoverUrl: @js($discoverUrl),
                    discoveryAvailable: @js($discoveryAvailable),
                })"
            >
                @csrf
                <input type="hidden" name="_modal" value="repository-create">

                <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Discover from GitHub App</p>
                            <p class="mt-1 text-xs text-gray-500" x-text="statusMessage"></p>
                        </div>
                        <button
                            type="button"
                            class="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-800 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            x-on:click="loadRepos()"
                            x-bind:disabled="loading || ! discoveryAvailable"
                        >
                            <span x-show="! loading">Load repositories</span>
                            <span x-show="loading" x-cloak>Loading…</span>
                        </button>
                    </div>

                    <div class="mt-3" x-show="repositories.length > 0" x-cloak>
                        <label class="block text-sm font-medium text-gray-700">Select a repository</label>
                        <select
                            class="mt-1 block w-full max-w-full truncate rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                            x-model="selectedFullName"
                            x-on:change="applySelection()"
                            x-bind:title="selectedOptionTitle()"
                        >
                            <option value="">— Choose discovered repository —</option>
                            <template x-for="repo in repositories" :key="repo.github_repo_id">
                                <option
                                    x-bind:value="repo.full_name"
                                    x-bind:disabled="repo.already_registered"
                                    x-bind:title="discoveryOptionTitle(repo)"
                                    x-text="discoveryOptionLabel(repo)"
                                ></option>
                            </template>
                        </select>
                    </div>
                </div>

                @include('admin.repositories.partials.form', [
                    'repository' => null,
                    'projects' => $projects,
                    'submitLabel' => 'Add Repository',
                    'idPrefix' => 'repository-create',
                    'modalName' => 'repository-create',
                ])
            </form>
        </x-modal>

        @foreach ($repositories as $repository)
            <x-modal name="repository-edit-{{ $repository->id }}" :show="old('_modal') === 'repository-edit-' . $repository->id" maxWidth="2xl" focusable>
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-900">Edit Repository</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ $repository->full_name }}</p>
                </div>

                <form method="POST" action="{{ route('admin.repositories.update', $repository) }}" class="space-y-6 p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_modal" value="repository-edit-{{ $repository->id }}">

                    @include('admin.repositories.partials.form', [
                        'repository' => $repository,
                        'projects' => $projects,
                        'submitLabel' => 'Update Repository',
                        'idPrefix' => 'repository-edit-' . $repository->id,
                        'modalName' => 'repository-edit-' . $repository->id,
                    ])
                </form>
            </x-modal>

            <x-modal name="repository-delete-{{ $repository->id }}" maxWidth="md">
                <div class="p-6">
                    <h3 class="text-base font-semibold text-gray-900">Delete Repository</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Delete {{ $repository->full_name }}?
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" x-on:click="$dispatch('close-modal', 'repository-delete-{{ $repository->id }}')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                            Cancel
                        </button>
                        <form method="POST" action="{{ route('admin.repositories.destroy', $repository) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            </x-modal>
        @endforeach
        @endcan
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('repositoryDiscovery', ({ discoverUrl, discoveryAvailable }) => ({
            discoverUrl,
            discoveryAvailable: !!discoveryAvailable,
            loading: false,
            repositories: [],
            selectedFullName: '',
            statusMessage: discoveryAvailable
                ? 'Click Load repositories to list GitHub App installation repos.'
                : 'GitHub App is not configured — enter owner/repository manually below.',

            discoveryOptionLabel(repo) {
                let label = repo.full_name || '';
                if (repo.private) {
                    label += ' (private)';
                }
                if (repo.already_registered) {
                    label += ' (registered)';
                }

                return label;
            },

            discoveryOptionTitle(repo) {
                const parts = [repo.full_name || ''];
                if (repo.private) {
                    parts.push('private');
                }
                if (repo.already_registered) {
                    parts.push('already registered');
                }

                return parts.filter(Boolean).join(' — ');
            },

            selectedOptionTitle() {
                if (! this.selectedFullName) {
                    return '';
                }
                const repo = this.repositories.find((item) => item.full_name === this.selectedFullName);

                return repo ? this.discoveryOptionTitle(repo) : this.selectedFullName;
            },

            async loadRepos() {
                if (! this.discoveryAvailable || this.loading) {
                    return;
                }

                this.loading = true;
                this.statusMessage = 'Fetching installation repositories…';

                try {
                    const response = await fetch(this.discoverUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });

                    const payload = await response.json();

                    if (! response.ok) {
                        this.statusMessage = 'Discovery request failed. Enter the repository manually.';
                        this.repositories = [];
                        return;
                    }

                    this.repositories = payload.repositories || [];
                    this.statusMessage = payload.available
                        ? (this.repositories.length
                            ? `Found ${this.repositories.length} repositories.`
                            : 'No repositories on this GitHub App installation.')
                        : (payload.message || 'Discovery unavailable. Enter the repository manually.');
                } catch (error) {
                    this.repositories = [];
                    this.statusMessage = 'Discovery request failed. Enter the repository manually.';
                } finally {
                    this.loading = false;
                }
            },

            applySelection() {
                const repo = this.repositories.find((item) => item.full_name === this.selectedFullName);
                if (! repo || repo.already_registered) {
                    return;
                }

                const nameInput = document.getElementById('repository-create-name');
                const fullNameInput = document.getElementById('repository-create-full_name');
                const githubIdInput = document.getElementById('repository-create-github_repo_id');
                const defaultBranchInput = document.getElementById('repository-create-default_branch');
                const branchesInput = document.getElementById('repository-create-branches');

                if (nameInput) nameInput.value = repo.name || '';
                if (fullNameInput) fullNameInput.value = repo.full_name || '';
                if (githubIdInput) githubIdInput.value = repo.github_repo_id || '';
                if (defaultBranchInput) defaultBranchInput.value = repo.default_branch || 'main';
                if (branchesInput && ! branchesInput.value.trim()) {
                    const defaults = [repo.default_branch || 'main', 'staging']
                        .filter((value, index, all) => value && all.indexOf(value) === index);
                    branchesInput.value = defaults.join('\n');
                }
            },
        }));
    });
</script>
@endpush
