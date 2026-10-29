<?php

namespace App\Rules;

use App\Models\Permission;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensures every *.manage permission has its matching *.view permission selected.
 */
class ManageRequiresViewPermission implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $permissions = Permission::query()
            ->whereIn('id', $value)
            ->get(['id', 'name', 'display_name']);

        $selectedNames = $permissions->pluck('name')->all();
        $viewPermissions = Permission::query()
            ->whereIn('name', collect($selectedNames)
                ->filter(fn (string $name) => str_ends_with($name, '.manage'))
                ->map(fn (string $name) => preg_replace('/\.manage$/', '.view', $name))
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->get(['name', 'display_name'])
            ->keyBy('name');

        $missingLabels = [];

        foreach ($permissions as $permission) {
            if (! str_ends_with($permission->name, '.manage')) {
                continue;
            }

            $viewName = preg_replace('/\.manage$/', '.view', $permission->name);

            if (! $viewPermissions->has($viewName)) {
                continue;
            }

            if (! in_array($viewName, $selectedNames, true)) {
                $missingLabels[] = $viewPermissions->get($viewName)->display_name;
            }
        }

        if ($missingLabels === []) {
            return;
        }

        $fail(
            'Please select the corresponding View permission as well: '
            .implode(', ', array_unique($missingLabels)).'.'
        );
    }
}
