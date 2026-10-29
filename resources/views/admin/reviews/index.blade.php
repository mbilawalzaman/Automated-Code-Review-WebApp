@extends('layouts.admin')

@section('title', 'Reviews')
@section('page-title', 'Reviews')

@section('content')
    @php
        $columns = [
            ['data' => 'review_formatted', 'name' => 'pull_request_title'],
            ['data' => 'repository_formatted', 'name' => 'repository.name'],
            ['data' => 'branch', 'name' => 'branch.name'],
            ['data' => 'findings_count', 'name' => 'findings_count', 'searchable' => false],
            ['data' => 'status_formatted', 'name' => 'status'],
            ['data' => 'completed_at_formatted', 'name' => 'completed_at'],
            ['data' => 'actions', 'name' => 'actions', 'orderable' => false, 'searchable' => false],
        ];
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Review History</h2>
            <p class="mt-1 text-sm text-gray-600">Completed review records, including objective-aware review outcomes.</p>
        </div>

        <div class="flex shrink-0 gap-2">
            @can('reports.view')
                <a href="{{ route('admin.exports.reviews', ['format' => 'csv']) }}"
                   class="rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-200">
                    Export CSV
                </a>
                <a href="{{ route('admin.exports.reviews', ['format' => 'json']) }}"
                   class="rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-200">
                    Export JSON
                </a>
            @endcan
        </div>
    </div>

    @if ($canOverrideReviews)
        <x-reauth-banner action="overriding a failed review" />
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <div
        class="mb-4 rounded-lg bg-white p-4 shadow"
        x-data="{
            repository: '',
            status: '',
            branch: '',
            author: '',
            dateFrom: '',
            dateTo: '',
            get filtersApplied() {
                return !!(this.repository || this.status || this.branch || this.author || this.dateFrom || this.dateTo);
            },
            resetFilters() {
                this.repository = '';
                this.status = '';
                this.branch = '';
                this.author = '';
                this.dateFrom = '';
                this.dateTo = '';
                this.$nextTick(() => window.reloadDataTables?.());
            },
        }"
    >
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="filter-repository" class="block text-xs font-medium text-gray-500">Repository</label>
                <select
                    id="filter-repository"
                    x-model="repository"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                >
                    <option value="">All repositories</option>
                    @foreach ($repositories as $repository)
                        <option value="{{ $repository->id }}">{{ $repository->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-status" class="block text-xs font-medium text-gray-500">Status</label>
                <select
                    id="filter-status"
                    x-model="status"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                >
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-branch" class="block text-xs font-medium text-gray-500">Branch</label>
                <input
                    id="filter-branch"
                    type="text"
                    x-model="branch"
                    placeholder="e.g. main"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                >
            </div>
            <div>
                <label for="filter-author" class="block text-xs font-medium text-gray-500">Developer</label>
                <input
                    id="filter-author"
                    type="text"
                    x-model="author"
                    placeholder="author"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                >
            </div>
            <div class="flex items-end gap-2">
                <div class="flex-1 min-w-0">
                    <label for="filter-date-from" class="block text-xs font-medium text-gray-500">From</label>
                    <input
                        id="filter-date-from"
                        type="date"
                        x-model="dateFrom"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                    >
                </div>
                <div class="flex-1 min-w-0">
                    <label for="filter-date-to" class="block text-xs font-medium text-gray-500">To</label>
                    <input
                        id="filter-date-to"
                        type="date"
                        x-model="dateTo"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                    >
                </div>
                <div x-show="filtersApplied" x-cloak class="shrink-0">
                    <button
                        type="button"
                        class="flex h-[42px] items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-900 whitespace-nowrap transition"
                        x-on:click="resetFilters()"
                    >
                        Reset filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="rounded-lg bg-white shadow p-4 sm:p-6 overflow-hidden">
        <div class="overflow-x-auto min-w-full">
            <table
                id="reviews-table"
                data-datatable
                data-ajax-url="{{ route('admin.reviews.data') }}"
                data-columns='@json($columns)'
                class="w-full divide-y divide-gray-200 min-w-[640px]"
            >
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="w-5/12 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Review</th>
                        <th scope="col" class="w-3/12 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Repository</th>
                        <th scope="col" class="w-20 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Branch</th>
                        <th scope="col" class="w-16 px-6 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Findings</th>
                        <th scope="col" class="w-28 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th scope="col" class="w-32 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Completed</th>
                        <th scope="col" class="w-28 px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>

    <x-modal name="review-detail" maxWidth="2xl">
        <div class="p-6" id="review-detail-body">
            <p class="text-sm text-gray-500">Loading…</p>
        </div>
    </x-modal>

    @if ($canOverrideReviews)
        <div
            x-data
            x-on:review-override.window="
                const detail = $event.detail;
                const form = document.getElementById('review-override-form');
                const titleEl = document.getElementById('review-override-title');
                if (form) form.action = detail.url;
                if (titleEl) titleEl.textContent = detail.title;
                $dispatch('open-modal', 'review-override');
            "
        >
            <x-modal name="review-override" :show="old('_modal') === 'review-override'" maxWidth="md" focusable>
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900">Override failed review</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Review: <span id="review-override-title" class="font-medium text-gray-900"></span>
                    </p>
                    <p class="mt-2 text-sm text-gray-500">
                        The review status stays failed for history. Override is recorded with your reason in the audit log.
                        Destructive migrations also require a recent verified backup (CHG-002). API contract changes require documentation (CHG-003).
                    </p>

                    <form id="review-override-form" method="POST" action="#" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="_modal" value="review-override">

                        <div>
                            <x-input-label for="override_reason" value="Override reason" />
                            <textarea
                                id="override_reason"
                                name="reason"
                                rows="4"
                                required
                                minlength="10"
                                maxlength="2000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >{{ old('reason') }}</textarea>
                            <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                        </div>

                        <div>
                            <x-input-label for="documentation_url" value="Documentation URL (API contract)" />
                            <x-text-input
                                id="documentation_url"
                                name="documentation_url"
                                type="url"
                                class="mt-1 block w-full"
                                :value="old('documentation_url')"
                                placeholder="https://..."
                            />
                            <x-input-error class="mt-2" :messages="$errors->get('documentation_url')" />
                        </div>

                        <div>
                            <x-input-label for="documentation_notes" value="Documentation notes (API contract)" />
                            <textarea
                                id="documentation_notes"
                                name="documentation_notes"
                                rows="3"
                                maxlength="5000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Breaking-change notes for consumers"
                            >{{ old('documentation_notes') }}</textarea>
                            <x-input-error class="mt-2" :messages="$errors->get('documentation_notes')" />
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'review-override')">
                                Cancel
                            </x-secondary-button>
                            <x-danger-button type="submit">
                                Confirm override
                            </x-danger-button>
                        </div>
                    </form>
                </div>
            </x-modal>
        </div>
    @endif
@endsection

@push('scripts')
<script>
document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-review-detail]');
    if (!trigger) return;

    const id = trigger.getAttribute('data-review-detail');
    const body = document.getElementById('review-detail-body');
    body.innerHTML = '<p class="text-sm text-gray-500">Loading…</p>';
    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'review-detail' }));

    try {
        const response = await fetch(`{{ url('/admin/reviews') }}/${id}`, {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        const tools = (data.tools_used || []).map((t) => `<li>${escapeHtml(t)}</li>`).join('') || '<li>None recorded</li>';
        const changeClasses = (data.change_classes || []).map((c) => `<li>${escapeHtml(c)}</li>`).join('') || '<li>None</li>';
        const impact = data.impact_analysis || {};
        const modules = (impact.affected_modules || []).map((m) => `<li>${escapeHtml(m)}</li>`).join('') || '<li>None</li>';
        const tables = (impact.database_tables || []).map((t) => `<li>${escapeHtml(t)}</li>`).join('') || '<li>None</li>';
        const toolExec = (data.tool_executions || []).map((t) => {
            const name = escapeHtml(t.tool || 'tool');
            const ver = t.version ? ` v${escapeHtml(String(t.version))}` : '';
            const dur = t.duration_ms != null ? ` · ${escapeHtml(String(t.duration_ms))}ms` : '';
            const exit = t.exit_code != null ? ` · exit ${escapeHtml(String(t.exit_code))}` : '';
            return `<li>${name}${ver}${dur}${exit}</li>`;
        }).join('') || '<li>None recorded</li>';
        const findings = (data.findings || []).map((f) => `
            <div class="rounded border border-gray-200 p-3">
                <div class="font-medium text-gray-900">[${escapeHtml(f.severity)}] ${escapeHtml(f.title)}${f.is_blocking ? ' · blocking' : ''}</div>
                <div class="text-xs text-gray-500 font-mono">${escapeHtml(f.file_path)}:${f.line_number ?? 'N/A'}</div>
                <div class="mt-2 text-sm text-gray-700">${escapeHtml(f.evidence || '')}</div>
                ${f.recommendation ? `<div class="mt-1 text-sm text-green-700"><strong>Fix:</strong> ${escapeHtml(f.recommendation)}</div>` : ''}
            </div>
        `).join('') || '<p class="text-sm text-gray-500">No findings.</p>';

        body.innerHTML = `
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Review #${data.id}</h3>
                    <p class="mt-1 text-sm text-gray-600">${escapeHtml(data.repository ?? 'N/A')} · ${escapeHtml(data.branch ?? 'N/A')}</p>
                </div>
                <button type="button" class="text-sm text-gray-500 hover:text-gray-800" onclick="window.dispatchEvent(new CustomEvent('close-modal', { detail: 'review-detail' }))">Close</button>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd class="font-medium">${escapeHtml(data.status)}</dd></div>
                <div><dt class="text-gray-500">Author</dt><dd class="font-medium">${escapeHtml(data.author ?? 'N/A')}</dd></div>
                <div><dt class="text-gray-500">Commit</dt><dd class="font-mono text-xs">${escapeHtml(data.commit_sha ?? 'N/A')}</dd></div>
                <div><dt class="text-gray-500">PR</dt><dd class="font-medium">#${escapeHtml(String(data.pull_request_number ?? 'N/A'))}</dd></div>
            </dl>
            <div class="mt-4">
                <h4 class="text-sm font-semibold text-gray-900">Summary</h4>
                <p class="mt-1 text-sm text-gray-700 whitespace-pre-wrap">${escapeHtml(data.summary || '')}</p>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <h4 class="text-sm font-semibold text-gray-900">Change classes</h4>
                    <ul class="mt-1 list-disc pl-5 text-sm text-gray-700">${changeClasses}</ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-gray-900">Affected modules</h4>
                    <ul class="mt-1 list-disc pl-5 text-sm text-gray-700">${modules}</ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-gray-900">Database tables</h4>
                    <ul class="mt-1 list-disc pl-5 text-sm text-gray-700">${tables}</ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-gray-900">Tools used</h4>
                    <ul class="mt-1 list-disc pl-5 text-sm text-gray-700">${tools}</ul>
                </div>
            </div>
            <div class="mt-4">
                <h4 class="text-sm font-semibold text-gray-900">Tool provenance</h4>
                <ul class="mt-1 list-disc pl-5 text-sm text-gray-700">${toolExec}</ul>
            </div>
            <div class="mt-4 space-y-2">
                <h4 class="text-sm font-semibold text-gray-900">Findings</h4>
                ${findings}
            </div>
        `;
    } catch (error) {
        body.innerHTML = '<p class="text-sm text-red-600">Unable to load review details.</p>';
    }
});

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
</script>
@endpush
