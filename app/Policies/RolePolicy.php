<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('roles.view') || $user->hasPermissionTo('roles.manage');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('roles.view') || $user->hasPermissionTo('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_system && $user->hasPermissionTo('roles.manage');
    }
}
