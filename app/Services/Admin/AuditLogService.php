<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Keys that must be sanitized before persisting to audit logs.
     */
    protected array $redactedKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'secret',
        'api_key',
        'private_key',
        'remember_token',
        'credentials',
    ];

    /**
     * Record an immutable audit log entry.
     */
    public function record(
        AuditCategory $category,
        AuditEventType $eventType,
        string $action,
        AuditSeverity $severity = AuditSeverity::Info,
        ?Model $target = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?array $metadata = null,
        ?string $reason = null,
        ?User $actor = null
    ): AuditLog {
        $actorId = $actor?->id ?? Auth::id();

        // Safely set $actorId to null if the user record does not exist in users table
        if ($actorId !== null && ! User::query()->where('id', $actorId)->exists()) {
            $actorId = null;
        }

        return AuditLog::query()->create([
            'user_id' => $actorId,
            'category' => $category,
            'event_type' => $eventType,
            'severity' => $severity,
            'action' => $action,
            'target_type' => $target ? get_class($target) : null,
            'target_id' => $target?->getKey(),
            'reason' => $reason,
            'before_state' => $beforeState ? $this->redactSensitiveData($beforeState) : null,
            'after_state' => $afterState ? $this->redactSensitiveData($afterState) : null,
            'metadata' => $metadata ? $this->redactSensitiveData($metadata) : null,
            'ip_address' => Request::ip() ?? '127.0.0.1',
            'user_agent' => Request::userAgent() ?? 'System',
        ]);
    }

    /**
     * Recursively redact sensitive keys from arrays.
     */
    public function redactSensitiveData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if (in_array($lowerKey, $this->redactedKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->redactSensitiveData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Helper to list paginated logs.
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function list(int $perPage = 10): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with('user')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }
}
