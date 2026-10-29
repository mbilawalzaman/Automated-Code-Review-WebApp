<?php

namespace App\Http\Middleware\Admin;

use App\Services\Admin\UnauthorizedAccessAuditor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Handle an incoming request to ensure the user has one of the required roles.
     *
     * @param  \Closure(Request): (Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (empty($roles)) {
            return $next($request);
        }

        $user->loadMissing('roles');

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        app(UnauthorizedAccessAuditor::class)->record(
            $request,
            'Missing required role: '.implode('|', $roles),
            $user,
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('admin.dashboard')
            ->with('error', 'Unauthorized action. You do not have permission to access that page.');
    }
}
