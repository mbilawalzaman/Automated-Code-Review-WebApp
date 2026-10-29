<?php

namespace App\Services\Admin;

use App\Events\Rbac\RoleUpdatedEvent;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RoleService
{
    public function list(): Collection
    {
        return Role::query()->with(['permissions', 'creator', 'users'])->withCount('users')->get();
    }

    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return Role::query()->with(['permissions', 'users', 'creator'])->latest()->paginate($perPage);
    }

    public function findOrFail(int $id): Role
    {
        return Role::query()->with(['permissions', 'creator'])->findOrFail($id);
    }

    public function create(array $data): Role
    {
        $role = Role::query()->create([
            'name' => Str::slug($data['display_name'], '_'),
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
            'created_by' => $data['created_by'] ?? auth()->id(),
        ]);

        if (! empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        RoleUpdatedEvent::dispatch(
            $role,
            "Created custom role: {$role->display_name}",
            [],
            $role->load('permissions')->toArray()
        );

        return $role;
    }

    public function update(Role $role, array $data): Role
    {
        $beforeState = $role->load('permissions')->toArray();

        $updateData = [
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
        ];

        if (! $role->is_system && isset($data['name'])) {
            $updateData['name'] = Str::slug($data['name'], '_');
        }

        $role->update($updateData);

        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        $afterState = $role->fresh('permissions')->toArray();

        RoleUpdatedEvent::dispatch(
            $role,
            "Updated role configuration: {$role->display_name}",
            $beforeState,
            $afterState
        );

        return $role;
    }

    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw new InvalidArgumentException('Protected system core roles cannot be deleted.');
        }

        $beforeState = $role->load('permissions')->toArray();
        $roleName = $role->display_name;

        $role->delete();

        RoleUpdatedEvent::dispatch(
            $role,
            "Deleted custom role: {$roleName}",
            $beforeState,
            []
        );
    }
}
