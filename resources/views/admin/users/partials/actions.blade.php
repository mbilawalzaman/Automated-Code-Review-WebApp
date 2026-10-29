<x-admin.actions-dropdown>
    <button
        type="button"
        class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
        x-on:click="$dispatch('open-modal', 'user-view-{{ $user->id }}'); open = false"
    >
        View
    </button>

    @can('users.manage')
        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
            x-on:click="$dispatch('open-modal', 'user-edit-{{ $user->id }}'); open = false"
        >
            Edit
        </button>

        @if(auth()->id() !== $user->id)
            <button
                type="button"
                class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50"
                x-on:click="$dispatch('open-modal', 'user-delete-{{ $user->id }}'); open = false"
            >
                Delete
            </button>
        @endif
    @endcan
</x-admin.actions-dropdown>
