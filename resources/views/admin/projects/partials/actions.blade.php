@can('projects.manage')
    <x-admin.actions-dropdown>
        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
            x-on:click="$dispatch('open-modal', 'project-edit-{{ $project->id }}'); open = false"
        >
            Edit
        </button>
        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50"
            x-on:click="$dispatch('open-modal', 'project-delete-{{ $project->id }}'); open = false"
        >
            Delete
        </button>
    </x-admin.actions-dropdown>
@else
    <span class="text-xs text-gray-400">—</span>
@endcan