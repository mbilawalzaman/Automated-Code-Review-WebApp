<?php

namespace App\Http\Middleware\Admin;

use App\Enums\UserRole;
use App\Services\Admin\UnauthorizedAccessAuditor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPanelAccess
{
    /**
     * Dashboard access for Project Manager (and Super Admin / Security Auditor).
     * Developers have no dashboard access by default (UI-001 / Stakeholder Roles).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $user->loadMissing('roles');

        $allowed = $user->hasRole(UserRole::SuperAdmin)
            || $user->hasRole(UserRole::ProjectManager)
            || $user->hasRole(UserRole::SecurityAuditor);

        if (! $allowed) {
            app(UnauthorizedAccessAuditor::class)->record(
                $request,
                'Role is not permitted dashboard access (UI-001)',
                $user,
            );

            abort(403, 'Dashboard access is restricted to Project Manager, Super Admin, and Security Auditor.');
        }

        return $next($request);
    }
}
