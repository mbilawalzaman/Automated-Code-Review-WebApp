<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Models\User;
use Illuminate\Http\Request;

class UnauthorizedAccessAuditor
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    public function record(Request $request, string $reason, ?User $user = null): void
    {
        $user ??= $request->user();
        $user?->loadMissing('roles');

        try {
            $this->auditLogService->record(
                category: AuditCategory::Security,
                eventType: AuditEventType::Unauthorized,
                action: 'Denied admin access',
                severity: AuditSeverity::Warning,
                target: $user,
                reason: $reason,
                afterState: [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'roles' => $user?->roles->pluck('name')->all() ?? [],
                ],
                actor: $user,
            );
        } catch (\Throwable) {
            // Never block the 403 on audit write failure.
        }
    }
}
