<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Dashboard') — {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('scripts')
</head>
<body
    class="font-sans antialiased bg-gray-100 min-h-screen"
    x-data="{ sidebarOpen: false }"
    @toggle-sidebar.window="sidebarOpen = !sidebarOpen"
>
    <div class="min-h-screen flex relative overflow-x-hidden">
        {{-- Mobile Backdrop Overlay --}}
        <div
            x-show="sidebarOpen"
            x-cloak
            x-transition:enter="transition-opacity ease-linear duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-linear duration-300"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-on:click="sidebarOpen = false"
            class="fixed inset-0 z-30 bg-gray-900/60 backdrop-blur-xs md:hidden"
        ></div>

        {{-- Sidebar Partial --}}
        @include('layouts.partials.sidebar')

        {{-- Main Content Shell --}}
        <div class="flex-1 flex flex-col min-h-screen min-w-0">
            {{-- Header Partial --}}
            @include('layouts.partials.header')

            {{-- Page Content --}}
            <main class="flex-1 p-4 sm:p-6">
                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-100 px-4 py-3 text-green-800 text-sm">
                        {{ session('status') }}
                    </div>
                @endif

                @if (session('error'))
                    <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700 flex items-center gap-2">
                        <svg class="h-5 w-5 text-red-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span>{{ session('error') }}</span>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
