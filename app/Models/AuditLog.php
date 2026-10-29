<?php

namespace App\Models;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'event_type',
        'severity',
        'action',
        'target_type',
        'target_id',
        'reason',
        'before_state',
        'after_state',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'category' => AuditCategory::class,
            'event_type' => AuditEventType::class,
            'severity' => AuditSeverity::class,
            'before_state' => 'array',
            'after_state' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new BadMethodCallException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new BadMethodCallException('Audit log entries are immutable and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }
}
