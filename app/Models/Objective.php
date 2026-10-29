<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Objective extends Model
{
    protected $fillable = [
        'project_id',
        'srs_document_id',
        'code',
        'title',
        'description',
        'is_mandatory',
        'sort_order',
        'is_approved',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function srsDocument(): BelongsTo
    {
        return $this->belongsTo(SrsDocument::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('is_approved', true);
    }

    /**
     * Shape sent to the review worker; deliberately excludes internal columns.
     *
     * @return array<string, mixed>
     */
    public function toWorkerPayload(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code ?? "OBJ-{$this->id}",
            'title' => $this->title,
            'description' => $this->description,
            'is_mandatory' => (bool) $this->is_mandatory,
        ];
    }
}
