@php
    $idPrefix = $idPrefix ?? 'repository';
    $modalName = $modalName ?? null;
    $cancelUrl = $cancelUrl ?? route('admin.repositories.index');
@endphp

<div class="space-y-6" x-data="{
    projectId: '{{ old('project_id', $repository?->project_id ?? '') }}'
}">
    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="{{ $idPrefix }}-project_id" class="block text-sm font-medium text-gray-700">Project</label>
            <select
                id="{{ $idPrefix }}-project_id"
                name="project_id"
                x-model="projectId"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                required
            >
                <option value="">Select project</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected((int) old('project_id', $repository?->project_id) === $project->id)>
                        {{ $project->name }}
                    </option>
                @endforeach
            </select>
            <p x-show="!projectId" class="mt-1 text-xs text-amber-600">
                Please select a project to enable repository fields.
            </p>
            @error('project_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="{{ $idPrefix }}-status" class="block text-sm font-medium text-gray-700">Status</label>
            <select
                id="{{ $idPrefix }}-status"
                name="status"
                :disabled="!projectId"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
                required
            >
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $repository?->status?->value ?? \App\Enums\RepositoryStatus::Disconnected->value) === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </select>
            @error('status')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="{{ $idPrefix }}-name" class="block text-sm font-medium text-gray-700">Repository Name</label>
            <input
                id="{{ $idPrefix }}-name"
                name="name"
                type="text"
                value="{{ old('name', $repository?->name) }}"
                :disabled="!projectId"
                :placeholder="!projectId ? 'Select a project first' : ''"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
                required
            >
            @error('name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="{{ $idPrefix }}-full_name" class="block text-sm font-medium text-gray-700">GitHub Full Name</label>
            <input
                id="{{ $idPrefix }}-full_name"
                name="full_name"
                type="text"
                value="{{ old('full_name', $repository?->full_name) }}"
                :disabled="!projectId"
                placeholder="organisation/repository"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
                required
            >
            @error('full_name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="{{ $idPrefix }}-github_repo_id" class="block text-sm font-medium text-gray-700">GitHub Repository ID</label>
            <input
                id="{{ $idPrefix }}-github_repo_id"
                name="github_repo_id"
                type="number"
                min="1"
                step="1"
                value="{{ old('github_repo_id', $repository?->github_repo_id) }}"
                :disabled="!projectId"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
            @error('github_repo_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="{{ $idPrefix }}-default_branch" class="block text-sm font-medium text-gray-700">Default Branch</label>
            <input
                id="{{ $idPrefix }}-default_branch"
                name="default_branch"
                type="text"
                value="{{ old('default_branch', $repository?->default_branch ?? 'main') }}"
                :disabled="!projectId"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
                required
            >
            @error('default_branch')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label for="{{ $idPrefix }}-branches" class="block text-sm font-medium text-gray-700">Branches</label>
        <textarea
            id="{{ $idPrefix }}-branches"
            name="branches"
            rows="4"
            :disabled="!projectId"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
        >{{ old('branches', $repository?->branches?->pluck('name')->implode("\n")) }}</textarea>
        <p class="mt-1 text-xs text-gray-500">Enter one branch per line. Main, master, and staging are marked protected.</p>
        @error('branches')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="{{ $idPrefix }}-trello_board_id" class="block text-sm font-medium text-gray-700">Trello Board ID</label>
            <input
                id="{{ $idPrefix }}-trello_board_id"
                name="trello_board_id"
                type="text"
                value="{{ old('trello_board_id', $repository?->trello_board_id) }}"
                :disabled="!projectId"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
            @error('trello_board_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="{{ $idPrefix }}-trello_list_id" class="block text-sm font-medium text-gray-700">Trello List ID</label>
            <input
                id="{{ $idPrefix }}-trello_list_id"
                name="trello_list_id"
                type="text"
                value="{{ old('trello_list_id', $repository?->trello_list_id) }}"
                :disabled="!projectId"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
            @error('trello_list_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        @php
            $existingReviewRules = is_array($repository?->review_rules) ? $repository->review_rules : [];
            $trustedAuthorsValue = old(
                'trusted_authors',
                isset($existingReviewRules['trusted_authors']) && is_array($existingReviewRules['trusted_authors'])
                    ? implode(', ', $existingReviewRules['trusted_authors'])
                    : ''
            );
            $reviewRulesForJson = $existingReviewRules;
            unset($reviewRulesForJson['trusted_authors']);
        @endphp
        <label for="{{ $idPrefix }}-trusted_authors" class="block text-sm font-medium text-gray-700">Trusted authors</label>
        <input
            id="{{ $idPrefix }}-trusted_authors"
            name="trusted_authors"
            type="text"
            value="{{ $trustedAuthorsValue }}"
            :disabled="!projectId"
            placeholder="alice, bob, acme-bot"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
        >
        <p class="mt-1 text-xs text-gray-500">
            Comma-separated GitHub usernames allowed to run repository tests for this repo (WF-007). Leave empty for static-first only unless a PM triggers Review Now.
        </p>
        @error('trusted_authors')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="{{ $idPrefix }}-review_rules_json" class="block text-sm font-medium text-gray-700">Review Rules JSON</label>
            <textarea
                id="{{ $idPrefix }}-review_rules_json"
                name="review_rules_json"
                rows="5"
                :disabled="!projectId"
                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
            >{{ old('review_rules_json', $reviewRulesForJson !== [] ? json_encode($reviewRulesForJson, JSON_PRETTY_PRINT) : '') }}</textarea>
            <p class="mt-1 text-xs text-gray-500">Advanced. Trusted authors are managed in the field above and are merged on save.</p>
            @error('review_rules_json')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="{{ $idPrefix }}-notification_settings_json" class="block text-sm font-medium text-gray-700">Notification Settings JSON</label>
            <textarea
                id="{{ $idPrefix }}-notification_settings_json"
                name="notification_settings_json"
                rows="5"
                :disabled="!projectId"
                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60"
            >{{ old('notification_settings_json', $repository?->notification_settings ? json_encode($repository->notification_settings, JSON_PRETTY_PRINT) : '') }}</textarea>
            @error('notification_settings_json')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <label class="flex items-center gap-2">
        <input
            type="checkbox"
            name="is_paused"
            value="1"
            :disabled="!projectId"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900 disabled:cursor-not-allowed disabled:opacity-60"
            @checked(old('is_paused', $repository?->is_paused ?? false))
        >
        <span class="text-sm font-medium text-gray-700">Paused</span>
    </label>

    <div class="flex items-center justify-end gap-3 border-t border-gray-200 pt-6">
        @if ($modalName)
            <button type="button" x-on:click="$dispatch('close-modal', '{{ $modalName }}')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancel</button>
        @else
            <a href="{{ $cancelUrl }}" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancel</a>
        @endif
        <button
            type="submit"
            :disabled="!projectId"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white transition"
            :class="!projectId ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-800'"
        >
            {{ $submitLabel }}
        </button>
    </div>
</div>
