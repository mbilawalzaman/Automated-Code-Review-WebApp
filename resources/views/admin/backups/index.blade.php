@extends('layouts.admin')

@section('title', 'Backups')
@section('page-title', 'Encrypted Backups')

@section('content')
    <div class="mb-6 sm:flex sm:items-center sm:justify-between">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Encrypted Backups</h2>
            <p class="mt-1 text-sm text-gray-600">Automated AES-256 encrypted system state archives (database, reports, audit logs, and manifest).</p>
        </div>
        @can('settings.manage')
            <div class="mt-4 sm:mt-0 flex gap-3">
                <form id="upload-restore-form" action="{{ route('admin.backups.restore') }}" method="POST" enctype="multipart/form-data" class="inline">
                    @csrf
                    <input
                        type="file"
                        name="backup_file"
                        id="backup_upload"
                        accept=".enc"
                        class="hidden"
                        onchange="if (this.files.length > 0) { window.dispatchEvent(new CustomEvent('set-backup-filename', { detail: this.files[0].name })); window.dispatchEvent(new CustomEvent('open-modal', { detail: 'backup-upload-restore' })); }"
                    >
                    <button
                        type="button"
                        onclick="document.getElementById('backup_upload').click()"
                        class="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-xs ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 transition"
                    >
                        <svg class="-ml-1 mr-2 h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                        Upload & Restore
                    </button>
                </form>
                <form action="{{ route('admin.backups.generate') }}" method="POST" class="inline">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 transition"
                    >
                        <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Create Backup Now
                    </button>
                </form>
            </div>
        @endcan
    </div>

    @can('settings.manage')
        <x-reauth-banner action="restoring a system backup" />
    @endcan

    {{-- Health & Summary Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <div class="overflow-hidden rounded-lg bg-white p-5 shadow">
            <dt class="truncate text-xs font-medium text-gray-500 uppercase tracking-wider">Total Archives</dt>
            <dd class="mt-1 text-2xl font-bold text-gray-900">{{ $stats['total_backups'] }}</dd>
        </div>
        <div class="overflow-hidden rounded-lg bg-white p-5 shadow">
            <dt class="truncate text-xs font-medium text-gray-500 uppercase tracking-wider">Storage Size</dt>
            <dd class="mt-1 text-2xl font-bold text-gray-900">{{ $stats['total_size_formatted'] }}</dd>
        </div>
        <div class="overflow-hidden rounded-lg bg-white p-5 shadow">
            <dt class="truncate text-xs font-medium text-gray-500 uppercase tracking-wider">Last Backup</dt>
            <dd class="mt-1 text-sm font-semibold text-gray-900 truncate">{{ $stats['last_backup_at'] }}</dd>
        </div>
        <div class="overflow-hidden rounded-lg bg-white p-5 shadow">
            <dt class="truncate text-xs font-medium text-gray-500 uppercase tracking-wider">Encryption / Retention</dt>
            <dd class="mt-1 flex items-center gap-2">
                <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-semibold text-green-700 ring-1 ring-green-600/20 ring-inset">
                    {{ $stats['encryption_cipher'] }}
                </span>
                <span class="text-xs text-gray-500">30d retention</span>
            </dd>
        </div>
    </div>

    {{-- Backups Table --}}
    <div class="rounded-lg bg-white shadow">
        <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 text-sm">Available System Backups</h3>
            <span class="text-xs text-red-500 font-semibold uppercase tracking-wider">Warning: Restoration overwrites live data</span>
        </div>
        <div class="overflow-x-auto min-w-full">
            <table class="w-full divide-y divide-gray-200 text-left text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="w-5/12 px-6 py-3 font-semibold uppercase tracking-wider text-xs text-gray-500">Archive File</th>
                        <th scope="col" class="w-1/6 px-6 py-3 font-semibold uppercase tracking-wider text-xs text-gray-500">Size</th>
                        <th scope="col" class="w-1/6 px-6 py-3 font-semibold uppercase tracking-wider text-xs text-gray-500">Encryption</th>
                        <th scope="col" class="w-1/5 px-6 py-3 font-semibold uppercase tracking-wider text-xs text-gray-500">Created At</th>
                        <th scope="col" class="w-28 px-6 py-3 text-right font-semibold uppercase tracking-wider text-xs text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($backups as $backup)
                        <tr class="hover:bg-gray-50/50">
                            <td class="px-6 py-4 font-mono text-xs font-medium text-gray-900">
                                {{ $backup['filename'] }}
                            </td>
                            <td class="px-6 py-4 text-gray-600">
                                {{ $backup['size_formatted'] }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-blue-700/10 ring-inset">
                                    AES-256 Encrypted
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-500 text-xs">
                                {{ $backup['created_at'] }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                @can('settings.manage')
                                    <x-admin.actions-dropdown placement="top">
                                        <form action="{{ route('admin.backups.verify', ['filename' => $backup['filename']]) }}" method="POST">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition"
                                                x-on:click="close()"
                                            >
                                                Verify Integrity
                                            </button>
                                        </form>
                                        <a
                                            href="{{ route('admin.backups.download', ['filename' => $backup['filename']]) }}"
                                            class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition"
                                            x-on:click="close()"
                                        >
                                            Download Archive
                                        </a>
                                        <button
                                            type="button"
                                            class="block w-full px-4 py-2 text-left text-sm font-medium text-red-700 hover:bg-red-50 transition"
                                            x-on:click="close(); $dispatch('open-modal', 'backup-restore-{{ $loop->index }}')"
                                        >
                                            Restore Backup
                                        </button>
                                    </x-admin.actions-dropdown>

                                    <x-modal name="backup-restore-{{ $loop->index }}" maxWidth="md">
                                        <div class="p-6 text-left">
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                                                    <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h3 class="text-base font-semibold text-gray-900">Restore System Backup</h3>
                                                    <p class="text-xs font-mono text-gray-500 mt-0.5">{{ $backup['filename'] }}</p>
                                                </div>
                                            </div>

                                            <p class="mt-4 text-sm text-gray-600">
                                                Are you sure you want to restore this system backup archive?
                                            </p>

                                            <div class="mt-3 rounded-md bg-red-50 p-3 text-xs font-semibold text-red-700 border border-red-200">
                                                ⚠️ WARNING: Restoring a backup will OVERWRITE your current database, reports, and system state!
                                            </div>

                                            <div class="mt-6 flex justify-end gap-3">
                                                <button
                                                    type="button"
                                                    x-on:click="$dispatch('close-modal', 'backup-restore-{{ $loop->index }}')"
                                                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition"
                                                >
                                                    Cancel
                                                </button>
                                                <form action="{{ route('admin.backups.restore') }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="filename" value="{{ $backup['filename'] }}">
                                                    <button
                                                        type="submit"
                                                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition"
                                                    >
                                                        Confirm Restore
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
                            <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                                No encrypted backups generated yet.
                                @can('settings.manage')
                                    Click "Create Backup Now" to create your first archive.
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pagination-footer :paginator="$backups" label="backups" />
    </div>

    @can('settings.manage')
        <x-modal name="backup-upload-restore" maxWidth="md">
            <div class="p-6 text-left" x-data="{ filename: '' }" x-on:set-backup-filename.window="filename = $event.detail">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Upload & Restore Archive</h3>
                        <p class="text-xs font-mono text-gray-500 mt-0.5" x-text="filename"></p>
                    </div>
                </div>

                <p class="mt-4 text-sm text-gray-600">
                    Are you sure you want to restore from this uploaded backup archive?
                </p>

                <div class="mt-3 rounded-md bg-red-50 p-3 text-xs font-semibold text-red-700 border border-red-200">
                    ⚠️ WARNING: Restoring an uploaded archive will OVERWRITE your current database and reports!
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        x-on:click="$dispatch('close-modal', 'backup-upload-restore')"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onclick="document.getElementById('upload-restore-form').submit()"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition"
                    >
                        Confirm Restore
                    </button>
                </div>
            </div>
        </x-modal>
    @endcan
@endsection
