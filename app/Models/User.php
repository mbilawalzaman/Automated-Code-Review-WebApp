<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'created_by',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    public function hasRole(UserRole|Role|string $role): bool
    {
        $roleName = match (true) {
            $role instanceof Role => $role->name,
            $role instanceof UserRole => $role->value,
            default => (string) $role,
        };

        $this->loadMissing('roles');

        if ($this->roles->contains('name', $roleName)) {
            return true;
        }

        // Fallback to legacy column if roles relation not attached yet
        return $this->role?->value === $roleName;
    }

    public function hasPermissionTo(Permission|string $permission): bool
    {
        // Super Admin bypasses all specific permission checks
        if ($this->isSuperAdmin()) {
            return true;
        }

        $permissionName = $permission instanceof Permission ? $permission->name : $permission;

        return in_array($permissionName, $this->permissionNames(), true);
    }

    /**
     * True if the user has any of the given permissions (single permission load).
     */
    public function hasAnyPermission(string ...$permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        $owned = $this->permissionNames();
        foreach ($permissions as $permission) {
            if (in_array($permission, $owned, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->permissionNameCache !== null) {
            return $this->permissionNameCache;
        }

        $this->loadMissing('roles.permissions');

        return $this->permissionNameCache = $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    /** @var list<string>|null */
    protected ?array $permissionNameCache = null;

    public function assignRole(Role|string $role): self
    {
        $roleModel = is_string($role)
            ? Role::query()->where('name', $role)->first()
            : $role;

        if ($roleModel) {
            $this->roles()->syncWithoutDetaching([$roleModel->id]);
            $this->unsetRelation('roles');
            $this->permissionNameCache = null;
        }

        return $this;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isProjectManager(): bool
    {
        return $this->hasRole('project_manager');
    }

    public function isSecurityAuditor(): bool
    {
        return $this->hasRole('security_auditor');
    }

    public function isDeveloper(): bool
    {
        return $this->hasRole('developer');
    }
}
