<?php

namespace App\Repositories\Eloquent;

use App\Models\Finding;
use App\Repositories\Contracts\FindingRepositoryInterface;
use Illuminate\Support\Arr;

class FindingRepository implements FindingRepositoryInterface
{
    public function count(): int
    {
        return Finding::query()->count();
    }

    public function create(array $data): Finding
    {
        return Finding::query()->create($data);
    }

    public function createMany(array $findings): array
    {
        if ($findings === []) {
            return [];
        }

        $now = now();
        $fillable = (new Finding)->getFillable();
        $rows = [];

        foreach ($findings as $findingData) {
            $row = Arr::only($findingData, $fillable);
            foreach (['severity', 'status'] as $enumField) {
                if (isset($row[$enumField]) && is_object($row[$enumField]) && property_exists($row[$enumField], 'value')) {
                    $row[$enumField] = $row[$enumField]->value;
                }
            }
            $row['is_blocking'] = (bool) ($row['is_blocking'] ?? false);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
        }

        $columnUnion = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                $columnUnion[$column] = true;
            }
        }
        $columns = array_keys($columnUnion);

        $normalizedRows = [];
        foreach ($rows as $row) {
            $normalized = [];
            foreach ($columns as $column) {
                $normalized[$column] = $row[$column] ?? null;
            }
            $normalizedRows[] = $normalized;
        }

        foreach (array_chunk($normalizedRows, 100) as $chunk) {
            Finding::query()->insert($chunk);
        }

        $reviewId = $normalizedRows[0]['review_id'] ?? null;
        if ($reviewId === null) {
            return [];
        }

        return Finding::query()
            ->where('review_id', $reviewId)
            ->where('created_at', $now)
            ->orderBy('id')
            ->get()
            ->all();
    }
}
