<?php

namespace App\Models;

use App\Enums\RepositoryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    /** @use HasFactory<\Database\Factories\RepositoryFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'full_name',
        'github_repo_id',
        'status',
        'is_paused',
        'default_branch',
        'trello_board_id',
        'trello_list_id',
        'notification_settings',
        'review_rules',
        'connected_at',
        'last_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RepositoryStatus::class,
            'is_paused' => 'boolean',
            'notification_settings' => 'array',
            'review_rules' => 'array',
            'connected_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function reviewJobs(): HasMany
    {
        return $this->hasMany(ReviewJob::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function srsDocuments(): HasMany
    {
        return $this->hasMany(SrsDocument::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Derived GitHub HTTPS clone URL for the worker engine.
     */
    public function cloneUrl(): ?string
    {
        if (! filled($this->full_name)) {
            return null;
        }

        return config('github.web_base').'/'.trim($this->full_name, '/').'.git';
    }

    /**
     * Derived GitHub HTML URL for the repository.
     */
    public function htmlUrl(): ?string
    {
        if (! filled($this->full_name)) {
            return null;
        }

        return config('github.web_base').'/'.trim($this->full_name, '/');
    }
}
