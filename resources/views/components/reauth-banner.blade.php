@props(['action' => 'this action'])

@php
    $confirmedAt = (int) session('auth.password_confirmed_at', 0);
    $timeout = (int) config('auth.password_timeout', 900);
    $needsConfirmation = (time() - $confirmedAt) > $timeout;
@endphp

@if ($needsConfirmation)
    <div class="mb-4 flex flex-col gap-2 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 sm:flex-row sm:items-center sm:justify-between">
        <span>
            For security, {{ $action }} requires you to confirm your password first.
        </span>
        <a
            href="{{ route('password.confirm', ['redirect' => request()->getRequestUri()]) }}"
            class="shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700"
        >
            Confirm password
        </a>
    </div>
@endif
