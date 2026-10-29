@extends('layouts.admin')

@section('title', 'User Management')
@section('page-title', 'User Management')

@section('content')
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-gray-900">System Users</h2>
            <p class="mt-1 text-sm text-gray-600">Manage user accounts, roles, and administrative access permissions.</p>
        </div>

        @can('users.manage')
            <button
                type="button"
                class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition shrink-0 self-start sm:self-auto"
                x-data
                x-on:click="$dispatch('open-modal', 'user-create')"
            >
                + New User
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
                id="users-table"
                data-datatable
                data-ajax-url="{{ route('admin.users.data') }}"
                data-columns='[
                    {"data": "name_formatted", "name": "name", "className": "align-top"},
                    {"data": "email", "name": "email", "className": "align-top"},
                    {"data": "role_formatted", "name": "role", "className": "align-top"},
                    {"data": "created_by_formatted", "name": "created_by", "className": "align-top"},
                    {"data": "created_at_formatted", "name": "created_at", "className": "align-top"},
                    {"data": "actions", "name": "actions", "orderable": false, "searchable": false, "className": "align-top text-right whitespace-nowrap", "width": "7rem"}
                ]'
                class="w-full table-fixed divide-y divide-gray-200 min-w-[800px]"
            >
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="w-[20%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Name</th>
                        <th scope="col" class="w-[24%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Email</th>
                        <th scope="col" class="w-[16%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Role</th>
                        <th scope="col" class="w-[16%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Created By</th>
                        <th scope="col" class="w-[16%] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Created</th>
                        <th scope="col" class="w-[8%] px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>

        @can('users.manage')
            <x-modal name="user-create" :show="old('_modal') === 'user-create'" maxWidth="2xl" focusable>
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-900">Create New User</h3>
                    <p class="mt-1 text-sm text-gray-600">Add a new user and assign their administrative RBAC role.</p>
                </div>

                <form method="POST" action="{{ route('admin.users.store') }}" class="p-6">
                    @csrf
                    <input type="hidden" name="_modal" value="user-create">

                    @include('admin.users.partials.form', [
                        'user' => null,
                        'roles' => $roles,
                        'submitLabel' => 'Create User',
                        'idPrefix' => 'user-create',
                        'modalName' => 'user-create',
                    ])
                </form>
            </x-modal>
        @endcan

        @foreach ($users as $user)
            <x-modal name="user-view-{{ $user->id }}" maxWidth="lg">
                <div class="border-b border-gray-200 px-6 py-4 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">User details</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $user->name }}</p>
                    </div>
                    <button
                        type="button"
                        class="text-sm text-gray-500 hover:text-gray-800"
                        x-on:click="$dispatch('close-modal', 'user-view-{{ $user->id }}')"
                    >
                        Close
                    </button>
                </div>

                <div class="space-y-4 p-6">
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 text-sm">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Name</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ $user->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Email</dt>
                            <dd class="mt-1 font-mono text-gray-900">{{ $user->email }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Role</dt>
                            <dd class="mt-1 text-gray-900">{{ $user->role?->label() ?? 'User' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Created by</dt>
                            <dd class="mt-1 text-gray-900">{{ $user->creator?->name ?? 'System' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Created</dt>
                            <dd class="mt-1 text-gray-900">{{ $user->created_at?->format('M d, Y H:i') ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Two-factor auth</dt>
                            <dd class="mt-1 text-gray-900">{{ $user->two_factor_confirmed_at ? 'Enabled' : 'Disabled' }}</dd>
                        </div>
                    </dl>

                    <div>
                        <h4 class="text-xs font-medium uppercase tracking-wide text-gray-500">Assigned RBAC roles</h4>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @forelse ($user->roles as $role)
                                <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/10">
                                    {{ $role->display_name }}
                                </span>
                            @empty
                                <span class="text-xs italic text-gray-400">No additional RBAC roles assigned</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-modal>

            @can('users.manage')
            <x-modal name="user-edit-{{ $user->id }}" :show="old('_modal') === 'user-edit-' . $user->id" maxWidth="2xl" focusable>
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-900">Edit User</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ $user->name }} ({{ $user->email }})</p>
                </div>

                <form method="POST" action="{{ route('admin.users.update', $user) }}" class="p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_modal" value="user-edit-{{ $user->id }}">

                    @include('admin.users.partials.form', [
                        'user' => $user,
                        'roles' => $roles,
                        'submitLabel' => 'Update User',
                        'idPrefix' => 'user-edit-' . $user->id,
                        'modalName' => 'user-edit-' . $user->id,
                    ])
                </form>
            </x-modal>

            @if(auth()->id() !== $user->id)
                <x-modal name="user-delete-{{ $user->id }}" maxWidth="md">
                    <div class="p-6">
                        <h3 class="text-base font-semibold text-gray-900">Delete User</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Are you sure you want to delete {{ $user->name }} ({{ $user->email }})?
                        </p>

                        <div class="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                x-on:click="$dispatch('close-modal', 'user-delete-{{ $user->id }}')"
                                class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                            >
                                Cancel
                            </button>
                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                    Delete User
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