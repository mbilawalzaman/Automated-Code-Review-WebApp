<?php

namespace App\Models;

use App\Enums\SrsDocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SrsDocument extends Model
{
    protected $fillable = [
        'project_id',
        'repository_id',
        'title',
        'version',
        'source_type',
        'source_url',
        'file_path',
        'original_filename',
        'content_hash',
        'extracted_text',
        'extracted_at',
        'status',
        'checklist',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SrsDocumentStatus::class,
            'checklist' => 'array',
            'extracted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class);
    }

    /**
     * Revisions that currently govern reviews (SRS-002).
     */
    public function scopeActiveApproved(Builder $query): Builder
    {
        return $query
            ->where('status', SrsDocumentStatus::Approved)
            ->whereNull('superseded_at');
    }

    /**
     * Repository-specific revisions, falling back to project-wide ones (ONB-005).
     */
    public function scopeForRepositoryOrProject(Builder $query, Repository $repository): Builder
    {
        return $query->where(function (Builder $q) use ($repository) {
            $q->where('repository_id', $repository->id)
                ->orWhere(function (Builder $projectScoped) use ($repository) {
                    $projectScoped->whereNull('repository_id')
                        ->where('project_id', $repository->project_id);
                });
        });
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [SrsDocumentStatus::Draft, SrsDocumentStatus::Rejected], true);
    }

    /**
     * Draft/rejected mistakes and pending submissions can be removed; approved
     * history stays immutable (create a new revision instead).
     */
    public function isDeletable(): bool
    {
        return in_array($this->status, [
            SrsDocumentStatus::Draft,
            SrsDocumentStatus::Rejected,
            SrsDocumentStatus::PendingApproval,
        ], true);
    }

    public function sourceLabel(): string
    {
        return match ($this->source_type) {
            'github' => 'GitHub',
            'google_drive' => 'Google Drive',
            default => 'Upload',
        };
    }
}
