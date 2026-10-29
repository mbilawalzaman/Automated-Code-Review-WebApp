<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'] ?? null,
            'repository_id' => $this->resource['repository_id'] ?? null,
            'branch' => $this->resource['branch'] ?? null,
            'status' => $this->resource['status'] ?? null,
            'started_at' => $this->resource['started_at'] ?? null,
            'completed_at' => $this->resource['completed_at'] ?? null,
        ];
    }
}
