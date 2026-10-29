<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'review_id',
        'channel',
        'type',
        'subject',
        'body',
        'status',
        'sent_at',
        'read_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
