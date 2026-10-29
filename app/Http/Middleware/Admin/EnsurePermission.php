<?php

namespace App\Http\Middleware\Admin;

use App\Services\Admin\UnauthorizedAccessAuditor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Handle an incoming request and evaluate database permissions.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin() || $user->hasAnyPermission(...$permissions)) {
            return $next($request);
        }

        app(UnauthorizedAccessAuditor::class)->record(
            $request,
            'Missing required permission: '.implode('|', $permissions),
            $user,
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Unauthorized action. Required permission missing.',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('admin.dashboard')
            ->with('error', 'Unauthorized action. You do not have permission to access that page.');
    }
}
