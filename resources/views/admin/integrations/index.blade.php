@extends('layouts.admin')

@section('title', 'Integrations')
@section('page-title', 'Integrations & Credentials')

@section('content')
    <div class="mb-6">
        <h2 class="text-base font-semibold text-gray-900">Live connectivity & credential rotation</h2>
        <p class="mt-1 text-sm text-gray-600">
            Probes hit GitHub, Trello, and Google Drive APIs. Rotated credentials are stored encrypted and read by live services (env remains fallback). Use <span class="font-medium">Use .env instead</span> to clear stored credentials for an integration.
        </p>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($probes as $key => $probe)
            <div class="rounded-lg bg-white p-5 shadow border border-gray-200">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ $probe['label'] }}</p>
                <p class="mt-2">
                    <span class="inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $probe['status_class'] }}">
                        {{ $probe['status_label'] }}
                    </span>
                </p>
                <p class="mt-2 text-xs text-gray-500">Source: {{ $probe['source'] ?? 'env' }}</p>
                @if (! empty($probe['detail']))
                    <p class="mt-1 text-xs text-gray-600">{{ $probe['detail'] }}</p>
                @endif
            </div>
        @endforeach
    </div>

    <x-reauth-banner action="rotating integration credentials" />

    {{-- Three cards in one row, equal height, Save buttons on the same bottom line. --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- GitHub --}}
        <article class="rounded-lg border border-gray-200 bg-white p-6 shadow" style="display: flex; flex-direction: column;">
            <h3 class="text-sm font-semibold text-gray-900">GitHub</h3>
            <p class="mt-1 text-xs text-gray-500">Current source: {{ $githubSource }}</p>

            <form method="POST" action="{{ route('admin.integrations.update') }}" class="mt-4" style="display: flex; flex-direction: column; flex: 1 1 auto;">
                @csrf
                <input type="hidden" name="type" value="github">

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">PAT token (optional fallback)</label>
                        <input type="password" name="token" class="mt-1 w-full rounded-md border-gray-300 text-sm" autocomplete="off">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">App ID</label>
                        <input type="text" name="app_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Installation ID</label>
                        <input type="text" name="installation_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Private key (PEM)</label>
                        <textarea name="private_key" rows="4" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs"></textarea>
                    </div>
                </div>

                <div aria-hidden="true" style="flex: 1 1 auto; min-height: 1rem;"></div>

                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                        Save GitHub credentials
                    </button>
                    @if ($githubSource === 'database')
                        <button
                            type="button"
                            x-on:click="$dispatch('open-modal', 'integration-clear-github')"
                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Use .env instead
                        </button>
                    @endif
                </div>
            </form>
        </article>

        {{-- Trello --}}
        <article class="rounded-lg border border-gray-200 bg-white p-6 shadow" style="display: flex; flex-direction: column;">
            <h3 class="text-sm font-semibold text-gray-900">Trello</h3>
            <p class="mt-1 text-xs text-gray-500">Current source: {{ $trelloSource }}</p>

            <form method="POST" action="{{ route('admin.integrations.update') }}" class="mt-4" style="display: flex; flex-direction: column; flex: 1 1 auto;">
                @csrf
                <input type="hidden" name="type" value="trello">

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">API key</label>
                        <input type="password" name="key" class="mt-1 w-full rounded-md border-gray-300 text-sm" autocomplete="off">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Token</label>
                        <input type="password" name="token" class="mt-1 w-full rounded-md border-gray-300 text-sm" autocomplete="off">
                    </div>
                </div>

                <div aria-hidden="true" style="flex: 1 1 auto; min-height: 1rem;"></div>

                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                        Save Trello credentials
                    </button>
                    @if ($trelloSource === 'database')
                        <button
                            type="button"
                            x-on:click="$dispatch('open-modal', 'integration-clear-trello')"
                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Use .env instead
                        </button>
                    @endif
                </div>
            </form>
        </article>

        {{-- Google Drive --}}
        <article class="rounded-lg border border-gray-200 bg-white p-6 shadow" style="display: flex; flex-direction: column;">
            <h3 class="text-sm font-semibold text-gray-900">Google Drive</h3>
            <p class="mt-1 text-xs text-gray-500">Current source: {{ $driveSource }}</p>

            <form method="POST" action="{{ route('admin.integrations.update') }}" class="mt-4" style="display: flex; flex-direction: column; flex: 1 1 auto;">
                @csrf
                <input type="hidden" name="type" value="google_drive">

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Service account JSON</label>
                        <textarea name="service_account" rows="4" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Folder ID</label>
                        <input type="text" name="folder_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Impersonation subject (optional)</label>
                        <input type="email" name="subject" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                </div>

                <div aria-hidden="true" style="flex: 1 1 auto; min-height: 1rem;"></div>

                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                        Save Drive credentials
                    </button>
                    @if ($driveSource === 'database')
                        <button
                            type="button"
                            x-on:click="$dispatch('open-modal', 'integration-clear-google-drive')"
                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Use .env instead
                        </button>
                    @endif
                </div>
            </form>
        </article>
    </div>

    @if ($githubSource === 'database')
        <x-modal name="integration-clear-github" maxWidth="md">
            <div class="p-6">
                <h3 class="text-base font-semibold text-gray-900">Use .env for GitHub</h3>
                <p class="mt-2 text-sm text-gray-600">
                    Clear stored GitHub credentials and read connection settings from environment variables instead?
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        x-on:click="$dispatch('close-modal', 'integration-clear-github')"
                        class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <form method="POST" action="{{ route('admin.integrations.clear') }}">
                        @csrf
                        <input type="hidden" name="type" value="github">
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Use .env instead
                        </button>
                    </form>
                </div>
            </div>
        </x-modal>
    @endif

    @if ($trelloSource === 'database')
        <x-modal name="integration-clear-trello" maxWidth="md">
            <div class="p-6">
                <h3 class="text-base font-semibold text-gray-900">Use .env for Trello</h3>
                <p class="mt-2 text-sm text-gray-600">
                    Clear stored Trello credentials and read connection settings from environment variables instead?
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        x-on:click="$dispatch('close-modal', 'integration-clear-trello')"
                        class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <form method="POST" action="{{ route('admin.integrations.clear') }}">
                        @csrf
                        <input type="hidden" name="type" value="trello">
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Use .env instead
                        </button>
                    </form>
                </div>
            </div>
        </x-modal>
    @endif

    @if ($driveSource === 'database')
        <x-modal name="integration-clear-google-drive" maxWidth="md">
            <div class="p-6">
                <h3 class="text-base font-semibold text-gray-900">Use .env for Google Drive</h3>
                <p class="mt-2 text-sm text-gray-600">
                    Clear stored Google Drive credentials and read connection settings from environment variables instead?
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        x-on:click="$dispatch('close-modal', 'integration-clear-google-drive')"
                        class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <form method="POST" action="{{ route('admin.integrations.clear') }}">
                        @csrf
                        <input type="hidden" name="type" value="google_drive">
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Use .env instead
                        </button>
                    </form>
                </div>
            </div>
        </x-modal>
    @endif
@endsection
