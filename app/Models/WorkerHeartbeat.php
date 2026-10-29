<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class WorkerHeartbeat extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id',
        'hostname',
        'os_info',
        'python_version',
        'last_ping_at',
        'payload',
        'is_currently_offline',
        'last_outage_alert_at',
        'current_outage_episode_id',
    ];

    protected function casts(): array
    {
        return [
            'last_ping_at' => 'datetime',
            'last_outage_alert_at' => 'datetime',
            'payload' => 'array',
            'is_currently_offline' => 'boolean',
        ];
    }

    /**
     * Determine whether the worker ping is recent enough to count as active.
     */
    public function getIsOnlineAttribute(): bool
    {
        if (! $this->last_ping_at) {
            return false;
        }

        $offlineAfterMinutes = (int) config('worker.offline_after_minutes', 15);

        return $this->last_ping_at->gte(Carbon::now()->subMinutes($offlineAfterMinutes));
    }
}
