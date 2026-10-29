<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\Admin\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    public function __construct(
        protected UserService $userService
    ) {}

    public function index(Request $request): View
    {
        $perPage = (int) $request->input('per_page', 10);
        $users = $this->userService->getPaginatedUsers($perPage);
        $roles = UserRole::cases();

        return view('admin.users.index', compact('users', 'roles'));
    }

    public function data(): JsonResponse
    {
        return DataTables::eloquent(User::query()->with(['roles', 'creator'])->latest())
            ->filterColumn('name_formatted', function ($query, $keyword) {
                $query->where('name', 'like', "%{$keyword}%");
            })
            ->filterColumn('role_formatted', function ($query, $keyword) {
                $query->where('role', 'like', "%{$keyword}%");
            })
            ->addColumn('name_formatted', function ($user) {
                $initials = strtoupper(substr($user->name ?: $user->email, 0, 2));
                return '<div class="flex items-center gap-3">' .
                       '<div class="h-9 w-9 shrink-0 rounded-full bg-emerald-600 flex items-center justify-center text-white font-semibold text-xs shadow-xs">' . e($initials) . '</div>' .
                       '<div class="font-semibold text-gray-900 text-sm">' . e($user->name) . '</div>' .
                       '</div>';
            })
            ->addColumn('email', function ($user) {
                return '<div class="text-sm text-gray-600 font-mono">' . e($user->email) . '</div>';
            })
            ->addColumn('role_formatted', function ($user) {
                $badgeColor = match ($user->role) {
                    UserRole::SuperAdmin => 'bg-purple-100 text-purple-800',
                    UserRole::ProjectManager => 'bg-blue-100 text-blue-800',
                    UserRole::SecurityAuditor => 'bg-amber-100 text-amber-800',
                    default => 'bg-gray-100 text-gray-800',
                };
                $label = $user->role?->label() ?? 'User';
                return '<span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ' . $badgeColor . '">' . e($label) . '</span>';
            })
            ->addColumn('created_by_formatted', function ($user) {
                return e($user->creator?->name ?? 'System');
            })
            ->addColumn('created_at_formatted', function ($user) {
                return e($user->created_at?->format('M d, Y H:i') ?? 'N/A');
            })
            ->addColumn('actions', function ($user) {
                return '<div class="flex justify-end pr-1">'
                    .view('admin.users.partials.actions', compact('user'))->render()
                    .'</div>';
            })
            ->rawColumns(['name_formatted', 'email', 'role_formatted', 'actions'])
            ->toJson();
    }

    public function create(): View
    {
        $roles = UserRole::cases();

        return view('admin.users.create', compact('roles'));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->userService->createUser($request->validated());

        return redirect()->route('admin.users.index')
            ->with('status', 'User created successfully.');
    }

    public function edit(User $user): View
    {
        $roles = UserRole::cases();

        return view('admin.users.edit', compact('user', 'roles'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->userService->updateUser($user, $request->validated(), $request->user());

        return redirect()->route('admin.users.index')
            ->with('status', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        try {
            $this->userService->deleteUser($user, $request->user());

            return redirect()->route('admin.users.index')
                ->with('status', 'User deleted successfully.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('admin.users.index')
                ->with('error', $e->getMessage());
        }
    }
}
