@extends('layouts.admin')

@section('title', 'Notifications')
@section('page-title', 'Notifications Inbox')

@section('content')
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-base font-semibold text-gray-900">System notifications</h2>
            <p class="mt-1 text-sm text-gray-600">List, filter, mark read, and delete alert notifications (NOT-004).</p>
        </div>
        @can('notifications.manage')
            <form method="POST" action="{{ route('admin.notifications.mark-all-read') }}">
                @csrf
                <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-xs ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    Mark all read
                </button>
            </form>
        @endcan
    </div>

    @php
        $filtersApplied = filled($filters['status'] ?? null)
            || filled($filters['channel'] ?? null)
            || ($filters['unread'] ?? false);
    @endphp

    <form
        method="GET"
        action="{{ route('admin.notifications.index') }}"
        id="notification-filters"
        class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow"
    >
        <div>
            <label for="filter-status" class="block text-xs font-medium text-gray-500">Status</label>
            <select
                id="filter-status"
                name="status"
                class="mt-1 rounded-md border-gray-300 text-sm"
                onchange="this.form.submit()"
            >
                <option value="">All statuses</option>
                @foreach (['pending', 'sent', 'failed'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-channel" class="block text-xs font-medium text-gray-500">Channel</label>
            <input
                id="filter-channel"
                type="text"
                name="channel"
                value="{{ $filters['channel'] ?? '' }}"
                placeholder="Channel"
                class="mt-1 rounded-md border-gray-300 text-sm"
                data-auto-submit-debounce="300"
            >
        </div>
        <label class="inline-flex items-center gap-2 pb-2 text-sm text-gray-700">
            <input
                type="checkbox"
                name="unread"
                value="1"
                @checked($filters['unread'] ?? false)
                class="rounded border-gray-300"
                onchange="this.form.submit()"
            >
            Unread only
        </label>

        @if ($filtersApplied)
            <div class="ml-auto flex items-center self-end pb-0.5">
                <a
                    href="{{ route('admin.notifications.index') }}"
                    class="rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 shadow-xs transition"
                >
                    Reset filters
                </a>
            </div>
        @endif
    </form>


    <div class="overflow-hidden rounded-lg bg-white shadow">
        <div class="overflow-x-auto min-w-full">
            <table class="w-full table-fixed divide-y divide-gray-200 text-sm min-w-[900px]">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="w-[22%] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Subject</th>
                        <th class="w-[30%] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Message</th>
                        <th class="w-[12%] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Channel</th>
                        <th class="w-[10%] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                        <th class="w-[12%] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Sent</th>
                        <th class="w-[14%] px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($notifications as $notification)
                        @php
                            $message = \Illuminate\Support\Str::limit(strip_tags((string) $notification->body), 120);
                        @endphp
                        <tr class="{{ $notification->isUnread() ? 'bg-indigo-50/40' : '' }}">
                            <td class="px-4 py-3">
                                <div class="truncate font-medium text-gray-900" title="{{ $notification->subject }}">{{ $notification->subject }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                <div class="truncate" title="{{ $message }}">{{ $message }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                <div class="truncate">{{ $notification->channel }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ is_object($notification->status) ? $notification->status->value : $notification->status }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ optional($notification->sent_at ?? $notification->created_at)->diffForHumans() }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('notifications.manage')
                                    <x-admin.actions-dropdown placement="top">
                                        @if ($notification->isUnread())
                                            <form method="POST" action="{{ route('admin.notifications.read', $notification) }}">
                                                @csrf
                                                <button
                                                    type="submit"
                                                    class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition"
                                                    x-on:click="close()"
                                                >
                                                    Mark as Read
                                                </button>
                                            </form>
                                        @endif
                                        <button
                                            type="button"
                                            class="block w-full px-4 py-2 text-left text-sm font-medium text-red-700 hover:bg-red-50 transition"
                                            x-on:click="close(); $dispatch('open-modal', 'notification-delete-{{ $notification->id }}')"
                                        >
                                            Delete Notification
                                        </button>
                                    </x-admin.actions-dropdown>

                                    <x-modal name="notification-delete-{{ $notification->id }}" maxWidth="md">
                                        <div class="p-6 text-left">
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                                                    <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h3 class="text-base font-semibold text-gray-900">Delete Notification</h3>
                                                    <p class="text-xs text-gray-500 mt-0.5">Remove alert notification from inbox</p>
                                                </div>
                                            </div>

                                            <p class="mt-4 text-sm text-gray-600">
                                                Are you sure you want to delete this notification?
                                            </p>

                                            <div class="mt-6 flex justify-end gap-3">
                                                <button
                                                    type="button"
                                                    x-on:click="$dispatch('close-modal', 'notification-delete-{{ $notification->id }}')"
                                                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition"
                                                >
                                                    Cancel
                                                </button>
                                                <form method="POST" action="{{ route('admin.notifications.destroy', $notification) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button
                                                        type="submit"
                                                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition"
                                                    >
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </x-modal>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No notifications found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pagination-footer :paginator="$notifications" label="notifications" />
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const input = document.querySelector('#notification-filters [data-auto-submit-debounce]');
    if (!input) return;

    let timer = null;
    const delay = Number(input.dataset.autoSubmitDebounce || 300);

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => input.form?.submit(), delay);
    });
});
</script>
@endpush
