@props([
    'role' => null,
    'permissionsByGroup' => [],
    'submitLabel' => 'Save Role',
    'idPrefix' => 'role-form',
    'modalName' => 'role-modal',
])

@php
    $assignedIds = $role ? $role->permissions->pluck('id')->toArray() : [];
    $allPermissions = collect($permissionsByGroup)->flatten(1);
    $viewPairByManageId = [];
    $managePairByViewId = [];

    foreach ($allPermissions as $permission) {
        if (! str_ends_with($permission->name, '.manage')) {
            continue;
        }

        $viewName = preg_replace('/\.manage$/', '.view', $permission->name);
        $viewPermission = $allPermissions->firstWhere('name', $viewName);

        if ($viewPermission) {
            $manageId = (string) $permission->id;
            $viewId = (string) $viewPermission->id;
            $viewPairByManageId[$manageId] = $viewId;
            $managePairByViewId[$viewId] = $manageId;
        }
    }
@endphp

<div
    class="space-y-6"
    x-data="{
        selectedPermissions: {{ json_encode(array_map('strval', old('permissions', $assignedIds))) }},
        viewPairByManageId: {{ json_encode($viewPairByManageId) }},
        managePairByViewId: {{ json_encode($managePairByViewId) }},
        onPermissionChange(permissionId, permissionName, isChecked) {
            permissionId = String(permissionId);

            if (permissionName.endsWith('.manage') && isChecked) {
                const viewId = this.viewPairByManageId[permissionId];
                if (viewId && ! this.selectedPermissions.includes(viewId)) {
                    this.selectedPermissions.push(viewId);
                }
            }

            if (permissionName.endsWith('.view') && ! isChecked) {
                const manageId = this.managePairByViewId[permissionId];
                if (manageId) {
                    this.selectedPermissions = this.selectedPermissions.filter((id) => id !== manageId);
                }
            }
        },
    }"
>
    <div>
        <label for="{{ $idPrefix }}-display-name" class="block text-sm font-medium text-gray-900">
            Role Name <span class="text-red-500">*</span>
        </label>
        <input
            type="text"
            id="{{ $idPrefix }}-display-name"
            name="display_name"
            value="{{ old('display_name', $role?->display_name) }}"
            placeholder="e.g. Manager, HR, Security Lead"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 sm:text-sm"
            required
        >
        @error('display_name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="{{ $idPrefix }}-description" class="block text-sm font-medium text-gray-900">
            Description
        </label>
        <textarea
            id="{{ $idPrefix }}-description"
            name="description"
            rows="2"
            placeholder="Briefly describe the access level and responsibilities for this role"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 sm:text-sm"
        >{{ old('description', $role?->description) }}</textarea>
        @error('description')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <h4 class="text-sm font-semibold text-gray-900 mb-3">Permissions Selection</h4>
        <p class="text-xs text-gray-500 mb-4">
            Click permission pills to assign or unassign permissions. Selecting a Manage permission automatically includes View for that module.
        </p>

        <div class="space-y-4">
            @foreach ($permissionsByGroup as $group => $permissions)
                <div class="rounded-lg border border-gray-200 bg-gray-50/50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-3 flex items-center justify-between">
                        <span>{{ str($group)->title() }} Module</span>
                        <span class="text-[10px] text-gray-400 font-normal">{{ count($permissions) }} permissions</span>
                    </div>

                    <div class="flex flex-wrap gap-2.5">
                        @foreach ($permissions as $perm)
                            <label
                                class="cursor-pointer inline-flex items-center justify-center min-w-[100px] rounded-full border px-4 py-2 text-xs font-medium transition-all select-none text-center leading-none"
                                :class="selectedPermissions.includes('{{ $perm->id }}')
                                    ? 'bg-gray-900 border-gray-900 text-white shadow-sm'
                                    : 'bg-white border-gray-300 text-gray-700 hover:border-gray-400 hover:bg-gray-50'"
                            >
                                <input
                                    type="checkbox"
                                    name="permissions[]"
                                    value="{{ $perm->id }}"
                                    class="sr-only"
                                    x-model="selectedPermissions"
                                    x-on:change="onPermissionChange('{{ $perm->id }}', '{{ $perm->name }}', $event.target.checked)"
                                >
                                <span class="text-center leading-none inline-block w-full">{{ $perm->display_name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        @error('permissions')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-gray-200">
        <button
            type="button"
            x-on:click="$dispatch('close-modal', '{{ $modalName }}')"
            class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
        >
            Cancel
        </button>

        <button
            type="submit"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
        >
            {{ $submitLabel }}
        </button>
    </div>
</div>
