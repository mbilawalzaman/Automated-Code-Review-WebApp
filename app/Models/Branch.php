<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'repository_id',
        'name',
        'is_protected',
        'review_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_protected' => 'boolean',
            'review_enabled' => 'boolean',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function reviewJobs(): HasMany
    {
        return $this->hasMany(ReviewJob::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
