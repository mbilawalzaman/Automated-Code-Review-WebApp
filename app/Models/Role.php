<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }

    public function givePermissionTo(Permission|string $permission): self
    {
        $perm = is_string($permission)
            ? Permission::query()->where('name', $permission)->first()
            : $permission;

        if ($perm) {
            $this->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $this;
    }

    public function syncPermissions(array $permissionIdsOrNames): self
    {
        $ids = [];
        foreach ($permissionIdsOrNames as $item) {
            if (is_numeric($item)) {
                $ids[] = (int) $item;
            } elseif (is_string($item)) {
                $perm = Permission::query()->where('name', $item)->first();
                if ($perm) {
                    $ids[] = $perm->id;
                }
            }
        }

        $this->permissions()->sync($ids);

        return $this;
    }

    public function hasPermissionTo(Permission|string $permission): bool
    {
        $name = $permission instanceof Permission ? $permission->name : $permission;

        return $this->permissions->contains('name', $name);
    }
}
