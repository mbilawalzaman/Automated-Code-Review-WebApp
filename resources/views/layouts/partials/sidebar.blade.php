<aside
    class="fixed inset-y-0 left-0 z-40 w-64 bg-gray-900 text-white flex flex-col shrink-0 min-h-screen transform transition-transform duration-300 ease-in-out md:translate-x-0 md:static md:inset-auto"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
>
    <div class="p-6 border-b border-gray-800 flex items-center justify-between">
        <div>
            <a href="{{ route('admin.dashboard') }}" class="text-xl font-semibold text-white">
                {{ config('app.name') }}
            </a>
            <p class="text-sm text-gray-400 mt-1">Management Dashboard</p>
        </div>
        {{-- Close Drawer Button on Mobile --}}
        <button
            type="button"
            x-on:click="sidebarOpen = false"
            class="md:hidden text-gray-400 hover:text-white p-1 rounded-md focus:outline-none"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
        @php
            $user = auth()->user();
            $canViewAudit = $user->isSuperAdmin() || $user->hasPermissionTo('audit.view');

            $allLinks = [
                ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'permission' => null],
                ['route' => 'admin.projects.index', 'label' => 'Projects', 'permission' => 'projects.view'],
                ['route' => 'admin.repositories.index', 'label' => 'Repositories', 'permission' => 'repositories.view'],
                ['route' => 'admin.srs.index', 'label' => 'Objectives & SRS', 'permission' => 'srs.view'],
                ['route' => 'admin.reviews.index', 'label' => 'Reviews', 'permission' => 'reviews.view'],
                ['route' => 'admin.findings.index', 'label' => 'Findings', 'permission' => 'findings.view'],
                ['route' => 'admin.reports.index', 'label' => 'Reports', 'permission' => 'reports.view'],
                ['route' => 'admin.notifications.index', 'label' => 'Notifications', 'permission' => 'notifications.view'],
                ['route' => 'admin.integrations.index', 'label' => 'Integrations', 'permission' => 'integrations.manage'],
                ['route' => 'admin.backups.index', 'label' => 'Backups', 'permission' => 'settings.manage'],
                ['route' => 'two-factor.show', 'label' => 'Two-Factor Auth', 'permission' => null],
                ['route' => 'admin.users.index', 'label' => 'Users', 'permissions' => ['users.view', 'users.manage']],
                ['route' => 'admin.roles.index', 'label' => 'Roles', 'permissions' => ['roles.view', 'roles.manage']],
            ];

            $links = array_filter($allLinks, function ($link) use ($user) {
                if ($user->isSuperAdmin()) {
                    return true;
                }
                if (! empty($link['permissions'] ?? null)) {
                    return $user->hasAnyPermission(...$link['permissions']);
                }
                if (empty($link['permission'])) {
                    return true;
                }

                return $user->hasPermissionTo($link['permission']);
            });

            $isAuditRoute = request()->routeIs('admin.audit-logs.*');
        @endphp

        @foreach ($links as $link)
            @php
                $isActive = request()->routeIs($link['route']);
            @endphp
            <a
                href="{{ route($link['route']) }}"
                x-on:click="sidebarOpen = false"
                class="flex items-center px-4 py-3 rounded-lg font-medium text-sm transition-colors {{ $isActive ? 'bg-gray-800 text-white font-semibold' : 'text-gray-400 hover:bg-gray-800/60 hover:text-white' }}"
            >
                {{ $link['label'] }}
            </a>
        @endforeach

        @if ($canViewAudit)
            <div x-data="{ open: {{ $isAuditRoute ? 'true' : 'false' }} }" class="pt-1">
                <button
                    type="button"
                    x-on:click="open = !open"
                    class="w-full flex items-center justify-between px-4 py-3 rounded-lg font-medium text-sm transition-colors text-gray-400 hover:bg-gray-800/60 hover:text-white"
                >
                    <span class="flex items-center">Audit</span>
                    <svg
                        class="w-4 h-4 transition-transform duration-200"
                        :class="open ? 'rotate-180 text-white' : 'text-gray-400'"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div x-show="open" x-collapse x-cloak class="pl-6 pr-2 py-1 space-y-1 border-l-2 border-gray-800 ml-4">
                    <a
                        href="{{ route('admin.audit-logs.index') }}"
                        x-on:click="sidebarOpen = false"
                        class="block px-3 py-2 rounded-md text-xs font-medium transition-colors {{ request()->routeIs('admin.audit-logs.index') ? 'text-white bg-gray-800 font-semibold' : 'text-gray-400 hover:text-white hover:bg-gray-800/40' }}"
                    >
                        Audit Logs
                    </a>
                    <a
                        href="{{ route('admin.audit-logs.analytics') }}"
                        x-on:click="sidebarOpen = false"
                        class="block px-3 py-2 rounded-md text-xs font-medium transition-colors {{ request()->routeIs('admin.audit-logs.analytics') ? 'text-white bg-gray-800 font-semibold' : 'text-gray-400 hover:text-white hover:bg-gray-800/40' }}"
                    >
                        Analytics & Operations
                    </a>
                    @can('system.health.view')
                        <a
                            href="{{ route('admin.health.index') }}"
                            x-on:click="sidebarOpen = false"
                            class="block px-3 py-2 rounded-md text-xs font-medium transition-colors {{ request()->routeIs('admin.health.index') ? 'text-white bg-gray-800 font-semibold' : 'text-gray-400 hover:text-white hover:bg-gray-800/40' }}"
                        >
                            System Health
                        </a>
                    @endcan
                </div>
            </div>
        @endif
    </nav>
</aside>
