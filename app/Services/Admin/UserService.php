<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\UserRole;
use App\Events\User\UserCreatedEvent;
use App\Events\User\UserUpdatedEvent;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected AuditLogService $auditLogService
    ) {}

    public function getPaginatedUsers(int $perPage = 10): LengthAwarePaginator
    {
        return $this->userRepository->paginate($perPage);
    }

    public function createUser(array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        $data['created_by'] ??= auth()->id();
        $roleValue = $data['role'] ?? UserRole::Developer->value;
        $data['role'] = $roleValue;

        $user = $this->userRepository->create($data);

        $roleModel = Role::query()->where('name', $roleValue)->first();
        if ($roleModel) {
            $user->roles()->sync([$roleModel->id]);
        }

        UserCreatedEvent::dispatch(
            $user,
            $user->only(['id', 'name', 'email', 'role'])
        );

        return $user;
    }

    public function updateUser(User $targetUser, array $data, User $currentUser): bool
    {
        $beforeState = $targetUser->only(['id', 'name', 'email', 'role']);

        // Self-protection: SuperAdmin cannot demote their own role
        if ($targetUser->id === $currentUser->id && isset($data['role']) && $data['role'] !== UserRole::SuperAdmin->value) {
            unset($data['role']);
        }

        if (! empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        if (isset($data['role'])) {
            $roleValue = $data['role'];
            $roleModel = Role::query()->where('name', $roleValue)->first();
            if ($roleModel) {
                $targetUser->roles()->sync([$roleModel->id]);
            }
        }

        $result = $this->userRepository->update($targetUser, $data);
        $afterState = $targetUser->fresh()->only(['id', 'name', 'email', 'role']);

        UserUpdatedEvent::dispatch(
            $targetUser,
            $beforeState,
            $afterState,
            $currentUser
        );

        return $result;
    }

    public function deleteUser(User $targetUser, User $currentUser): bool
    {
        // Self-protection: Users cannot delete themselves
        if ($targetUser->id === $currentUser->id) {
            throw new \InvalidArgumentException('You cannot delete your own account.');
        }

        $beforeState = $targetUser->only(['id', 'name', 'email', 'role']);

        $result = $this->userRepository->delete($targetUser);

        $this->auditLogService->record(
            category: AuditCategory::User,
            eventType: AuditEventType::Deleted,
            action: "User account deleted: {$targetUser->email}",
            severity: AuditSeverity::Warning,
            target: $targetUser,
            beforeState: $beforeState,
            reason: 'User account deletion by administrator',
            actor: $currentUser
        );

        return $result;
    }
}
