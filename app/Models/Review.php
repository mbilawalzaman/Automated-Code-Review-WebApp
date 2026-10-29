<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    /** @use HasFactory<\Database\Factories\ReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'repository_id',
        'branch_id',
        'review_job_id',
        'status',
        'commit_sha',
        'pull_request_number',
        'pull_request_title',
        'author',
        'started_at',
        'completed_at',
        'summary',
        'tools_used',
        'coverage_percentage',
        'changed_code_coverage',
        'test_summary',
        'execution_authorized',
        'detected_stacks',
        'change_classes',
        'impact_analysis',
        'tool_executions',
        'github_check_run_id',
        'trello_card_id',
        'trello_comment_id',
        'trello_message_id',
        'overridden_by',
        'override_reason',
        'overridden_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'tools_used' => 'array',
            'test_summary' => 'array',
            'detected_stacks' => 'array',
            'change_classes' => 'array',
            'impact_analysis' => 'array',
            'tool_executions' => 'array',
            'execution_authorized' => 'boolean',
            'coverage_percentage' => 'float',
            'changed_code_coverage' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'overridden_at' => 'datetime',
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

    public function reviewJob(): BelongsTo
    {
        return $this->belongsTo(ReviewJob::class);
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
