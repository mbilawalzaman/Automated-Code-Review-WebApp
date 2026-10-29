@php
    $protection = $activationPreview['branch_protection'] ?? [
        'branches' => ['main', 'staging'],
        'required_status_checks' => [config('github.check_run_name', 'Automated Code Review')],
        'required_approving_review_count' => 1,
    ];
@endphp

@can('repositories.manage')
    <x-admin.actions-dropdown>
        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
            x-on:click="$dispatch('open-modal', 'repository-edit-{{ $repository->id }}'); open = false"
        >
            Edit
        </button>

        @if (! in_array($repository->status, [\App\Enums\RepositoryStatus::Disconnected, \App\Enums\RepositoryStatus::Paused], true))
            <button
                type="button"
                class="block w-full px-4 py-2 text-left text-sm text-indigo-700 hover:bg-indigo-50"
                x-on:click="$dispatch('open-modal', 'repository-review-now-{{ $repository->id }}'); open = false"
            >
                Review Now
            </button>
        @endif

        @if ($repository->status !== \App\Enums\RepositoryStatus::Disconnected)
            <form method="POST" action="{{ route('admin.repositories.protect-branches', $repository) }}">
                @csrf
                <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Protect branches</button>
            </form>
        @endif

        @if ($repository->status !== \App\Enums\RepositoryStatus::Connected)
            <form method="POST" action="{{ route('admin.repositories.connect', $repository) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-blue-700 hover:bg-blue-50">Connect</button>
            </form>
        @endif

        @if ($repository->status !== \App\Enums\RepositoryStatus::Active)
            <button
                type="button"
                class="block w-full px-4 py-2 text-left text-sm text-green-700 hover:bg-green-50"
                x-on:click="$dispatch('open-modal', 'repository-activate-{{ $repository->id }}'); open = false"
            >
                Activate
            </button>
        @endif

        @if ($repository->is_paused)
            <form method="POST" action="{{ route('admin.repositories.resume', $repository) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-green-700 hover:bg-green-50">Resume</button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.repositories.pause', $repository) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-yellow-700 hover:bg-yellow-50">Pause</button>
            </form>
        @endif

        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50"
            x-on:click="$dispatch('open-modal', 'repository-delete-{{ $repository->id }}'); open = false"
        >
            Delete
        </button>
    </x-admin.actions-dropdown>

    @if ($repository->status !== \App\Enums\RepositoryStatus::Active)
        <x-modal name="repository-activate-{{ $repository->id }}" maxWidth="2xl">
            <div class="p-6">
                <h3 class="text-base font-semibold text-gray-900">Activate repository</h3>
                <p class="mt-2 text-sm text-gray-600">
                    Activating <span class="font-medium text-gray-900">{{ $repository->full_name }}</span> will queue a baseline review and apply the GitHub changes below (ONB-003). Nothing is reviewed until you approve.
                </p>

                <div class="mt-4 max-h-[28rem] space-y-4 overflow-y-auto pr-1 text-sm text-gray-700">
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <p class="font-medium text-gray-900">Proposed webhooks</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($activationPreview['webhooks'] ?? [] as $hook)
                                <li>
                                    <span class="font-medium">{{ $hook['event'] }}</span>
                                    → <code class="text-xs">{{ $hook['url'] }}</code>
                                    <span class="block text-xs text-gray-500">{{ $hook['action'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <p class="font-medium text-gray-900">Proposed checks</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($activationPreview['checks'] ?? [] as $check)
                                <li>
                                    <span class="font-medium">{{ $check['name'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $check['action'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <p class="font-medium text-gray-900">Required GitHub App permissions</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($activationPreview['permissions'] ?? [] as $perm)
                                <li>
                                    <span class="font-medium">{{ $perm['name'] }}</span>
                                    ({{ $perm['level'] }})
                                    <span class="block text-xs text-gray-500">{{ $perm['reason'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <p class="font-medium text-gray-900">Proposed branch protection</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>Branches: {{ implode(', ', $protection['branches'] ?? []) }}</li>
                            <li>Require pull request before merge</li>
                            <li>Required approving reviews: {{ $protection['required_approving_review_count'] ?? 1 }}</li>
                            <li>Required status check: {{ implode(', ', $protection['required_status_checks'] ?? []) }}</li>
                            <li>Block direct pushes / force-pushes / deletions</li>
                        </ul>
                        <p class="mt-3 text-xs text-gray-500">
                            Existing CI checks and review restrictions are preserved and merged. Missing branches on GitHub are skipped.
                        </p>
                    </div>

                    @if (! empty($activationPreview['notes']))
                        <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
                            <p class="font-medium">Notes</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach ($activationPreview['notes'] as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        x-on:click="$dispatch('close-modal', 'repository-activate-{{ $repository->id }}')"
                        class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <form method="POST" action="{{ route('admin.repositories.activate', $repository) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                            Approve &amp; activate
                        </button>
                    </form>
                </div>
            </div>
        </x-modal>
    @endif

    @if (! in_array($repository->status, [\App\Enums\RepositoryStatus::Disconnected, \App\Enums\RepositoryStatus::Paused], true))
        <x-modal name="repository-review-now-{{ $repository->id }}" maxWidth="md">
            <div class="p-6">
                <h3 class="text-base font-semibold text-gray-900">Review Now</h3>
                <p class="mt-2 text-sm text-gray-600">
                    Queue an immediate review for <span class="font-medium text-gray-900">{{ $repository->full_name }}</span>.
                    Choose a branch, or leave blank to use the default branch
                    (<code class="text-xs">{{ $repository->default_branch ?? 'main' }}</code>.
                </p>

                <form method="POST" action="{{ route('admin.repositories.review-now', $repository) }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <x-input-label for="review-now-branch-{{ $repository->id }}" value="Branch" />
                        <x-text-input
                            id="review-now-branch-{{ $repository->id }}"
                            name="branch"
                            type="text"
                            class="mt-1 block w-full"
                            :value="old('branch', $repository->default_branch ?? 'main')"
                            placeholder="{{ $repository->default_branch ?? 'main' }}"
                        />
                        <p class="mt-1 text-xs text-gray-500">Examples: main, staging, feature/login</p>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button
                            type="button"
                            x-on:click="$dispatch('close-modal', 'repository-review-now-{{ $repository->id }}')"
                            class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                        >
                            Cancel
                        </button>
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Queue review
                        </button>
                    </div>
                </form>
            </div>
        </x-modal>
    @endif
@else
    <span class="text-xs text-gray-400">—</span>
@endcan
