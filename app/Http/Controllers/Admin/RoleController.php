<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Rules\ManageRequiresViewPermission;
use App\Services\Admin\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class RoleController extends Controller
{
    public function __construct(
        protected RoleService $roleService
    ) {}

    public function index(): View
    {
        $roles = $this->roleService->list();
        $permissionsByGroup = Permission::query()->get()->groupBy('group');

        return view('admin.roles.index', compact('roles', 'permissionsByGroup'));
    }

    public function data(): JsonResponse
    {
        return DataTables::eloquent(Role::query()->with(['permissions', 'creator'])->withCount('users')->latest())
            ->filter(function ($query) {
                if ($keyword = request('search.value')) {
                    $query->where(function ($q) use ($keyword) {
                        $q->where('name', 'like', "%{$keyword}%")
                          ->orWhere('display_name', 'like', "%{$keyword}%")
                          ->orWhere('description', 'like', "%{$keyword}%");
                    });
                }
            })
            ->addColumn('role_formatted', function ($role) {
                return '<div class="font-semibold text-gray-900 text-sm">' . e($role->display_name) . '</div>';
            })
            ->addColumn('type_formatted', function ($role) {
                return $role->is_system
                    ? '<span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-700/10">System</span>'
                    : '<span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">Custom</span>';
            })
            ->addColumn('description_formatted', function ($role) {
                return '<div class="text-xs text-gray-600 leading-relaxed">' . e($role->description ?? 'N/A') . '</div>';
            })
            ->addColumn('created_by_formatted', function ($role) {
                return e($role->creator?->name ?? ($role->is_system ? 'System' : '—'));
            })
            ->addColumn('users_count_formatted', function ($role) {
                return '<span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-800">' . $role->users_count . ' users</span>';
            })
            ->addColumn('permissions_formatted', function ($role) {
                if ($role->permissions->isEmpty()) {
                    return '<span class="text-xs italic text-gray-400">No permissions assigned</span>';
                }

                $total = $role->permissions->count();
                $visible = $role->permissions->take(6);
                $html = '<div class="flex flex-wrap gap-1.5">';

                foreach ($visible as $perm) {
                    $html .= '<span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10">'
                        .e($perm->display_name)
                        .'</span>';
                }

                if ($total > 6) {
                    $html .= '<span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700" title="'
                        .e($role->permissions->skip(6)->pluck('display_name')->implode(', '))
                        .'">+'
                        .($total - 6)
                        .' more</span>';
                }

                $html .= '</div>';

                return $html;
            })
            ->addColumn('actions', function ($role) {
                return '<div class="flex justify-end pr-1">'
                    .view('admin.roles.partials.actions', compact('role'))->render()
                    .'</div>';
            })
            ->rawColumns(['role_formatted', 'type_formatted', 'description_formatted', 'users_count_formatted', 'permissions_formatted', 'actions'])
            ->toJson();
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $slug = Str::slug((string) $value, '_');

                    if ($slug === '' || Role::query()->where('name', $slug)->orWhere('display_name', $value)->exists()) {
                        $fail('A role with this name already exists. Choose a different display name.');
                    }
                },
            ],
            'description' => ['nullable', 'string'],
            'permissions' => ['nullable', 'array', new ManageRequiresViewPermission()],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $this->roleService->create($validated);

        return redirect()->route('admin.roles.index')
            ->with('status', 'Role created successfully.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($role): void {
                    $slug = Str::slug((string) $value, '_');

                    $taken = Role::query()
                        ->where('id', '!=', $role->id)
                        ->where(function ($query) use ($slug, $value) {
                            $query->where('name', $slug)->orWhere('display_name', $value);
                        })
                        ->exists();

                    if ($slug === '' || $taken) {
                        $fail('A role with this name already exists. Choose a different display name.');
                    }
                },
            ],
            'description' => ['nullable', 'string'],
            'permissions' => ['nullable', 'array', new ManageRequiresViewPermission()],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $this->roleService->update($role, $validated);

        return redirect()->route('admin.roles.index')
            ->with('status', 'Role updated successfully.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        try {
            $this->roleService->delete($role);

            return redirect()->route('admin.roles.index')
                ->with('status', 'Role deleted successfully.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('admin.roles.index')
                ->with('error', $e->getMessage());
        }
    }
}
