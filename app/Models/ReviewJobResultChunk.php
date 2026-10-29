<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewJobResultChunk extends Model
{
    protected $fillable = [
        'review_job_id',
        'chunk_index',
        'findings',
    ];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'chunk_index' => 'integer',
        ];
    }

    public function reviewJob(): BelongsTo
    {
        return $this->belongsTo(ReviewJob::class);
    }
}
