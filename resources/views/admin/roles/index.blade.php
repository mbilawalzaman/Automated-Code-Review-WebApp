@extends('layouts.admin')

@section('title', 'Role Management')
@section('page-title', 'Role Management')

@section('content')
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Role & Permission Management</h2>
            <p class="mt-1 text-sm text-gray-600">Configure access control roles, protected system roles, and fine-grained permission assignments.</p>
        </div>

        @can('roles.manage')
            <button
                type="button"
                class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition shrink-0 self-start sm:self-auto"
                x-data
                x-on:click="$dispatch('open-modal', 'role-create')"
            >
                + Create Role
            </button>
        @endcan
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-100 p-4 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <div class="rounded-lg bg-white shadow p-4 sm:p-6 overflow-hidden">
        <div class="overflow-x-auto min-w-full">
            <table
                id="roles-table"
                data-datatable
                data-ajax-url="{{ route('admin.roles.data') }}"
                data-columns='[
                    {"data": "role_formatted", "name": "display_name", "className": "align-top"},
                    {"data": "type_formatted", "name": "is_system", "className": "align-top"},
                    {"data": "description_formatted", "name": "description", "className": "align-top"},
                    {"data": "created_by_formatted", "name": "created_by", "orderable": false, "className": "align-top"},
                    {"data": "users_count_formatted", "name": "users_count", "searchable": false, "className": "align-top"},
                    {"data": "permissions_formatted", "name": "permissions", "orderable": false, "className": "align-top"},
                    {"data": "actions", "name": "actions", "orderable": false, "searchable": false, "className": "align-top text-right whitespace-nowrap", "width": "7rem"}
                ]'
                class="w-full table-fixed divide-y divide-gray-200 min-w-[960px]"
            >
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="w-[13%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Role</th>
                        <th scope="col" class="w-[8%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Type</th>
                        <th scope="col" class="w-[22%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Description</th>
                        <th scope="col" class="w-[12%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Created By</th>
                        <th scope="col" class="w-[10%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Members</th>
                        <th scope="col" class="w-[27%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Permissions</th>
                        <th scope="col" class="w-[8%] px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>

        @can('roles.manage')
        {{-- Create Role Modal --}}
        <x-modal name="role-create" :show="old('_modal') === 'role-create'" maxWidth="2xl" focusable>
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-base font-semibold text-gray-900">Create New Role</h3>
                <p class="mt-1 text-sm text-gray-600">Define a custom role and select its assigned permissions.</p>
            </div>

            <form method="POST" action="{{ route('admin.roles.store') }}" class="p-6">
                @csrf
                <input type="hidden" name="_modal" value="role-create">

                @include('admin.roles.partials.form', [
                    'role' => null,
                    'permissionsByGroup' => $permissionsByGroup,
                    'submitLabel' => 'Create Role',
                    'idPrefix' => 'role-create',
                    'modalName' => 'role-create',
                ])
            </form>
        </x-modal>
        @endcan

        {{-- View / Edit / Delete Modals --}}
        @foreach ($roles as $role)
            <x-modal name="role-view-{{ $role->id }}" maxWidth="2xl">
                <div class="border-b border-gray-200 px-6 py-4 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Role details</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $role->display_name }}</p>
                    </div>
                    <button
                        type="button"
                        class="text-sm text-gray-500 hover:text-gray-800"
                        x-on:click="$dispatch('close-modal', 'role-view-{{ $role->id }}')"
                    >
                        Close
                    </button>
                </div>

                <div class="space-y-4 p-6">
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 text-sm">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Display name</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ $role->display_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Internal name</dt>
                            <dd class="mt-1 font-mono text-gray-900">{{ $role->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Type</dt>
                            <dd class="mt-1 text-gray-900">{{ $role->is_system ? 'System' : 'Custom' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Created by</dt>
                            <dd class="mt-1 text-gray-900">{{ $role->creator?->name ?? ($role->is_system ? 'System' : '—') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Members</dt>
                            <dd class="mt-1 text-gray-900">{{ $role->users_count ?? $role->users->count() }} users</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Created</dt>
                            <dd class="mt-1 text-gray-900">{{ $role->created_at?->format('M d, Y H:i') ?? 'N/A' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Description</dt>
                            <dd class="mt-1 text-gray-900">{{ $role->description ?: 'N/A' }}</dd>
                        </div>
                    </dl>

                    <div>
                        <h4 class="text-xs font-medium uppercase tracking-wide text-gray-500">Permissions ({{ $role->permissions->count() }})</h4>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @forelse ($role->permissions as $permission)
                                <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/10">
                                    {{ $permission->display_name }}
                                </span>
                            @empty
                                <span class="text-xs italic text-gray-400">No permissions assigned</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-modal>

            @can('roles.manage')
            <x-modal name="role-edit-{{ $role->id }}" :show="old('_modal') === 'role-edit-' . $role->id" maxWidth="2xl" focusable>
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-900">Edit Role</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ $role->display_name }}</p>
                </div>

                <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_modal" value="role-edit-{{ $role->id }}">

                    @include('admin.roles.partials.form', [
                        'role' => $role,
                        'permissionsByGroup' => $permissionsByGroup,
                        'submitLabel' => 'Update Role',
                        'idPrefix' => 'role-edit-' . $role->id,
                        'modalName' => 'role-edit-' . $role->id,
                    ])
                </form>
            </x-modal>

            @if(!$role->is_system)
                <x-modal name="role-delete-{{ $role->id }}" maxWidth="md">
                    <div class="p-6">
                        <h3 class="text-base font-semibold text-gray-900">Delete Role</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Are you sure you want to delete {{ $role->display_name }}? Users assigned to this role will lose these permissions.
                        </p>

                        <div class="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                x-on:click="$dispatch('close-modal', 'role-delete-{{ $role->id }}')"
                                class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                            >
                                Cancel
                            </button>
                            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                    Delete Role
                                </button>
                            </form>
                        </div>
                    </div>
                </x-modal>
            @endif
            @endcan
        @endforeach
    </div>
@endsection
