<?php

namespace App\Listeners;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Events\Rbac\RoleUpdatedEvent;
use App\Events\Review\ReviewOverriddenEvent;
use App\Events\Security\SecurityEventOccurred;
use App\Events\User\UserCreatedEvent;
use App\Events\User\UserUpdatedEvent;
use App\Models\User;
use App\Services\Admin\AuditLogService;
use Illuminate\Auth\Events\Failed as FrameworkLoginFailed;
use Illuminate\Auth\Events\Login as FrameworkLogin;
use Illuminate\Auth\Events\Logout as FrameworkLogout;

class AuditLogListener
{
    public function __construct(
        protected AuditLogService $auditLogService
    ) {}

    public function handleFrameworkLogin(FrameworkLogin $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $this->auditLogService->record(
            category: AuditCategory::Auth,
            eventType: AuditEventType::Login,
            action: "User logged in: {$user->email}",
            severity: AuditSeverity::Info,
            target: $user,
            afterState: ['email' => $user->email, 'name' => $user->name, 'guard' => $event->guard],
            actor: $user
        );
    }

    public function handleFrameworkFailed(FrameworkLoginFailed $event): void
    {
        $attemptedEmail = is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : 'Unknown';

        $this->auditLogService->record(
            category: AuditCategory::Auth,
            eventType: AuditEventType::LoginFailed,
            action: "Failed login attempt for email: {$attemptedEmail}",
            severity: AuditSeverity::Warning,
            metadata: ['attempted_email' => $attemptedEmail, 'guard' => $event->guard],
            reason: 'Invalid authentication credentials provided',
            actor: $event->user
        );
    }

    public function handleFrameworkLogout(FrameworkLogout $event): void
    {
        if (! $event->user) {
            return;
        }

        /** @var User $user */
        $user = $event->user;

        $this->auditLogService->record(
            category: AuditCategory::Auth,
            eventType: AuditEventType::Logout,
            action: "User logged out: {$user->email}",
            severity: AuditSeverity::Info,
            target: $user,
            afterState: ['email' => $user->email, 'guard' => $event->guard],
            actor: $user
        );
    }

    public function handleUserCreated(UserCreatedEvent $event): void
    {
        $this->auditLogService->record(
            category: AuditCategory::User,
            eventType: AuditEventType::Created,
            action: "User account created: {$event->user->email}",
            severity: AuditSeverity::Info,
            target: $event->user,
            afterState: $event->afterState,
            actor: $event->actor
        );
    }

    public function handleUserUpdated(UserUpdatedEvent $event): void
    {
        $this->auditLogService->record(
            category: AuditCategory::User,
            eventType: AuditEventType::Updated,
            action: "User account updated: {$event->user->email}",
            severity: AuditSeverity::Info,
            target: $event->user,
            beforeState: $event->beforeState,
            afterState: $event->afterState,
            actor: $event->actor
        );
    }

    public function handleRoleUpdated(RoleUpdatedEvent $event): void
    {
        $this->auditLogService->record(
            category: AuditCategory::Rbac,
            eventType: AuditEventType::Updated,
            action: $event->action,
            severity: AuditSeverity::Warning,
            target: $event->role,
            beforeState: $event->beforeState,
            afterState: $event->afterState,
            actor: $event->actor
        );
    }

    public function handleReviewOverridden(ReviewOverriddenEvent $event): void
    {
        $this->auditLogService->record(
            category: AuditCategory::Review,
            eventType: AuditEventType::Override,
            action: "Manual override applied to Review #{$event->review->id}",
            severity: AuditSeverity::Critical,
            target: $event->review,
            afterState: array_filter([
                'review_id' => $event->review->id,
                'status' => $event->review->status,
                'override_reason' => $event->reason,
                'change_classes' => $event->review->change_classes,
                'documentation_url' => $event->context['documentation_url'] ?? null,
                'documentation_notes' => $event->context['documentation_notes'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            reason: $event->reason,
            actor: $event->actor
        );
    }

    public function handleSecurityEvent(SecurityEventOccurred $event): void
    {
        $this->auditLogService->record(
            category: AuditCategory::Security,
            eventType: AuditEventType::Unauthorized,
            action: $event->action,
            severity: $event->severity,
            metadata: $event->metadata,
            reason: $event->reason,
            actor: $event->actor
        );
    }
}
