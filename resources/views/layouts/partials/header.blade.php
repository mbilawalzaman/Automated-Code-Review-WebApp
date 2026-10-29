<header class="bg-white shadow-sm border-b border-gray-200">
    <div class="flex items-center justify-between px-4 sm:px-6 py-4">
        <div class="flex items-center gap-3 min-w-0">
            {{-- Mobile Hamburger Menu Button --}}
            <button
                type="button"
                x-on:click="$dispatch('toggle-sidebar')"
                class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-md md:hidden focus:outline-none"
                aria-label="Toggle navigation menu"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <h1 class="text-base sm:text-lg font-semibold text-gray-800 truncate">
                @yield('page-title', 'Dashboard')
            </h1>
        </div>

        <div x-data="{ open: false }" class="relative shrink-0">
            @php
                $user = auth()->user();
                $initials = str($user->name ?: $user->email)
                    ->explode(' ')
                    ->filter()
                    ->map(fn (string $part) => str($part)->substr(0, 1)->upper())
                    ->take(2)
                    ->implode('');

                if ($initials === '') {
                    $initials = 'QA';
                }
            @endphp

            <div class="flex items-center gap-2 sm:gap-3">
                <span class="hidden sm:inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 border border-gray-200">
                    {{ $user->role?->label() ?? 'User' }}
                </span>

                <button
                    type="button"
                    class="inline-flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-full bg-green-500 text-xs sm:text-sm font-semibold text-white ring-2 ring-green-100 hover:bg-green-600"
                    aria-haspopup="true"
                    x-bind:aria-expanded="open.toString()"
                    x-on:click="open = ! open"
                    x-on:keydown.escape.window="open = false"
                >
                    <span class="sr-only">Open user menu</span>
                    {{ $initials }}
                </button>
            </div>

            <div
                x-cloak
                x-show="open"
                x-transition.origin.top.right
                x-on:click.outside="open = false"
                class="absolute right-0 z-50 mt-3 w-56 rounded-lg border border-gray-200 bg-white py-2 text-left shadow-lg"
            >
                <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
                    <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-green-500 text-xs font-semibold text-white">
                        {{ $initials }}
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-gray-900">{{ $user->name }}</div>
                        <div class="truncate text-xs text-gray-500">{{ $user->email }}</div>
                        <div class="mt-1 text-xs font-semibold text-green-600">{{ $user->role?->label() ?? 'User' }}</div>
                    </div>
                </div>

                <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                    <span>Profile</span>
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">
                        <span>Logout</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
