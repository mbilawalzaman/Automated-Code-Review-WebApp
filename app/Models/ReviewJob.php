<?php

namespace App\Models;

use App\Enums\ReviewJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReviewJob extends Model
{
    protected $fillable = [
        'repository_id',
        'branch_id',
        'status',
        'trigger_type',
        'commit_sha',
        'pull_request_number',
        'github_check_run_id',
        'trello_card_id',
        'trello_comment_id',
        'idempotency_key',
        'payload',
        'attempts',
        'scheduled_at',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewJobStatus::class,
            'payload' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
}
