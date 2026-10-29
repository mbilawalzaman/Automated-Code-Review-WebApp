<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepositoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'] ?? null,
            'project_id' => $this->resource['project_id'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'full_name' => $this->resource['full_name'] ?? null,
            'status' => $this->resource['status'] ?? null,
            'created_at' => $this->resource['created_at'] ?? null,
            'updated_at' => $this->resource['updated_at'] ?? null,
        ];
    }
}
