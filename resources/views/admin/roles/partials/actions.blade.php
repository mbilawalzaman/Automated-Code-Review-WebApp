<x-admin.actions-dropdown>
    <button
        type="button"
        class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition"
        x-on:click="close(); $dispatch('open-modal', 'role-view-{{ $role->id }}')"
    >
        View
    </button>

    @can('roles.manage')
        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition"
            x-on:click="close(); $dispatch('open-modal', 'role-edit-{{ $role->id }}')"
        >
            Edit Role
        </button>

        @if (! $role->is_system)
            <button
                type="button"
                class="block w-full px-4 py-2 text-left text-sm font-medium text-red-700 hover:bg-red-50 transition"
                x-on:click="close(); $dispatch('open-modal', 'role-delete-{{ $role->id }}')"
            >
                Delete Role
            </button>
        @endif
    @endcan
</x-admin.actions-dropdown>
