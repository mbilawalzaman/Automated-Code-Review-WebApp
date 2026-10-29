<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'metadata',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function srsDocuments(): HasMany
    {
        return $this->hasMany(SrsDocument::class);
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
