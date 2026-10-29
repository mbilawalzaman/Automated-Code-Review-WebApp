<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'] ?? null,
            'review_id' => $this->resource['review_id'] ?? null,
            'severity' => $this->resource['severity'] ?? null,
            'file' => $this->resource['file'] ?? null,
            'line' => $this->resource['line'] ?? null,
            'message' => $this->resource['message'] ?? null,
            'recommendation' => $this->resource['recommendation'] ?? null,
        ];
    }
}
