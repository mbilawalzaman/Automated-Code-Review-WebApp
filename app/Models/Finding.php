<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Finding extends Model
{
    /** @use HasFactory<\Database\Factories\FindingFactory> */
    use HasFactory;

    protected $fillable = [
        'review_id',
        'parent_finding_id',
        'objective_id',
        'severity',
        'status',
        'file_path',
        'line_number',
        'rule_id',
        'fingerprint',
        'source',
        'title',
        'evidence',
        'objective_impact',
        'recommendation',
        'is_blocking',
        'resolved_in_review_id',
        'resolved_at',
        'false_positive_reason',
    ];

    protected function casts(): array
    {
        return [
            'severity' => FindingSeverity::class,
            'status' => FindingStatus::class,
            'is_blocking' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function parentFinding(): BelongsTo
    {
        return $this->belongsTo(Finding::class, 'parent_finding_id');
    }

    public function childFindings(): HasMany
    {
        return $this->hasMany(Finding::class, 'parent_finding_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    public function resolvedInReview(): BelongsTo
    {
        return $this->belongsTo(Review::class, 'resolved_in_review_id');
    }
}
