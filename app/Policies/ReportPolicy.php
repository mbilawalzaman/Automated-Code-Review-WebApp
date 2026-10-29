<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isProjectManager() || $user->isSecurityAuditor();
    }

    public function view(User $user, Report $report): bool
    {
        return $user->isSuperAdmin() || $user->isProjectManager() || $user->isSecurityAuditor();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isProjectManager();
    }
}
