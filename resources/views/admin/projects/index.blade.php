@extends('layouts.admin')

@section('title', 'Projects')
@section('page-title', 'Projects')

@section('content')
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Project Management</h2>
            <p class="mt-1 text-sm text-gray-600">Create and maintain the project profiles that group repositories, objectives, SRS documents, and integrations.</p>
        </div>

        @can('projects.manage')
        <button
            type="button"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 shrink-0 self-start sm:self-auto"
            x-data
            x-on:click="$dispatch('open-modal', 'project-create')"
        >
            Create Project
        </button>
        @endcan
    </div>

    <div class="rounded-lg bg-white shadow p-4 sm:p-6 overflow-hidden">
        <div class="overflow-x-auto min-w-full">
            <table
                id="projects-table"
                data-datatable
                data-ajax-url="{{ route('admin.projects.data') }}"
                data-columns='[
                    {"data": "name", "name": "name", "width": "18%"},
                    {"data": "slug", "name": "slug", "width": "16%"},
                    {"data": "description", "name": "description", "orderable": false, "width": "28%"},
                    {"data": "repositories_count", "name": "repositories_count", "searchable": false, "width": "12%"},
                    {"data": "status_formatted", "name": "is_active", "width": "12%"},
                    {"data": "actions", "name": "actions", "orderable": false, "searchable": false, "width": "14%"}
                ]'
                class="w-full table-fixed divide-y divide-gray-200 min-w-[800px]"
            >
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="w-[18%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Project</th>
                        <th scope="col" class="w-[16%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Slug</th>
                        <th scope="col" class="w-[28%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Description</th>
                        <th scope="col" class="w-[12%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Repositories</th>
                        <th scope="col" class="w-[12%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th scope="col" class="w-[14%] px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>

        @can('projects.manage')
        {{-- Modals --}}
        <x-modal name="project-create" :show="old('_modal') === 'project-create'" maxWidth="2xl" focusable>
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-base font-semibold text-gray-900">Create Project</h3>
                <p class="mt-1 text-sm text-gray-600">Add a project profile to group repositories and review objectives.</p>
            </div>

            <form method="POST" action="{{ route('admin.projects.store') }}" class="space-y-6 p-6">
                @csrf
                <input type="hidden" name="_modal" value="project-create">

                @include('admin.projects.partials.form', [
                    'project' => null,
                    'submitLabel' => 'Create Project',
                    'idPrefix' => 'project-create',
                    'modalName' => 'project-create',
                ])
            </form>
        </x-modal>

        @foreach ($projects as $project)
            <x-modal name="project-edit-{{ $project->id }}" :show="old('_modal') === 'project-edit-' . $project->id" maxWidth="2xl" focusable>
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-900">Edit Project</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ $project->name }}</p>
                </div>

                <form method="POST" action="{{ route('admin.projects.update', $project) }}" class="space-y-6 p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_modal" value="project-edit-{{ $project->id }}">

                    @include('admin.projects.partials.form', [
                        'project' => $project,
                        'submitLabel' => 'Update Project',
                        'idPrefix' => 'project-edit-' . $project->id,
                        'modalName' => 'project-edit-' . $project->id,
                    ])
                </form>
            </x-modal>

            <x-modal name="project-delete-{{ $project->id }}" maxWidth="md">
                <div class="p-6">
                    <h3 class="text-base font-semibold text-gray-900">Delete Project</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Delete {{ $project->name }} and its related repository configuration?
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" x-on:click="$dispatch('close-modal', 'project-delete-{{ $project->id }}')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                            Cancel
                        </button>
                        <form method="POST" action="{{ route('admin.projects.destroy', $project) }}">
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
