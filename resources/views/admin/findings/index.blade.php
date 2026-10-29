@extends('layouts.admin')

@section('title', 'Findings')
@section('page-title', 'Findings')

@section('content')
    @php
        $columns = [
            ['data' => 'finding_formatted', 'name' => 'title'],
            ['data' => 'repository_formatted', 'name' => 'review.repository.name'],
            ['data' => 'location_formatted', 'name' => 'file_path'],
            ['data' => 'objective', 'name' => 'objective.code'],
            ['data' => 'severity_formatted', 'name' => 'severity'],
            ['data' => 'status_formatted', 'name' => 'status'],
        ];

        if ($canManageFindings) {
            $columns[] = ['data' => 'actions', 'name' => 'actions', 'orderable' => false, 'searchable' => false];
        }
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Findings</h2>
            <p class="mt-1 text-sm text-gray-600">Security, quality, and objective-related findings recorded by completed reviews.</p>
        </div>

        <div class="flex shrink-0 gap-2">
            <a href="{{ route('admin.exports.findings', ['format' => 'csv']) }}"
               class="rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-200">
                Export CSV
            </a>
            <a href="{{ route('admin.exports.findings', ['format' => 'json']) }}"
               class="rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-200">
                Export JSON
            </a>
        </div>
    </div>

    @if ($canManageFindings)
        <x-reauth-banner action="marking findings as false positive or accepted" />
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-4 grid grid-cols-1 gap-3 rounded-lg bg-white p-4 shadow sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label for="filter-repository" class="block text-xs font-medium text-gray-500">Repository</label>
            <select id="filter-repository" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                <option value="">All repositories</option>
                @foreach ($repositories as $repository)
                    <option value="{{ $repository->id }}">{{ $repository->full_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-severity" class="block text-xs font-medium text-gray-500">Severity</label>
            <select id="filter-severity" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                <option value="">All severities</option>
                @foreach ($severities as $severity)
                    <option value="{{ $severity->value }}">{{ $severity->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-status" class="block text-xs font-medium text-gray-500">Status</label>
            <select id="filter-status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ str($status->value)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-date-from" class="block text-xs font-medium text-gray-500">From</label>
            <input id="filter-date-from" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
        </div>
        <div>
            <label for="filter-date-to" class="block text-xs font-medium text-gray-500">To</label>
            <input id="filter-date-to" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
        </div>
    </div>

    <div class="rounded-lg bg-white shadow p-4 sm:p-6 overflow-hidden">
        <div class="overflow-x-auto min-w-full">
            <table
                id="findings-table"
                data-datatable
                data-ajax-url="{{ route('admin.findings.data') }}"
                data-columns='@json($columns)'
                class="w-full divide-y divide-gray-200 min-w-[640px]"
            >
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="w-3/12 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Finding</th>
                        <th scope="col" class="w-3/12 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Repository</th>
                        <th scope="col" class="w-2/12 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Location</th>
                        <th scope="col" class="w-2/12 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Objective</th>
                        <th scope="col" class="w-20 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Severity</th>
                        <th scope="col" class="w-24 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        @if ($canManageFindings)
                            <th scope="col" class="w-28 px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                        @endif
                    </tr>
                </thead>
            </table>
        </div>
    </div>

    <x-modal name="finding-detail" maxWidth="2xl">
        <div class="p-6" id="finding-detail-body">
            <p class="text-sm text-gray-500">Loading…</p>
        </div>
    </x-modal>

    @if ($canManageFindings)
        <div
            x-data
            x-on:finding-lifecycle.window="
                const detail = $event.detail;
                const form = document.getElementById('finding-lifecycle-form');
                const titleEl = document.getElementById('finding-lifecycle-title');
                const headingEl = document.getElementById('finding-lifecycle-heading');
                const submitEl = document.getElementById('finding-lifecycle-submit');
                if (form) form.action = detail.url;
                if (titleEl) titleEl.textContent = detail.title;
                if (detail.mode === 'accept') {
                    if (headingEl) headingEl.textContent = 'Accept finding';
                    if (submitEl) submitEl.textContent = 'Accept as warning';
                } else {
                    if (headingEl) headingEl.textContent = 'Mark false positive';
                    if (submitEl) submitEl.textContent = 'Confirm false positive';
                }
                $dispatch('open-modal', 'finding-lifecycle');
            "
        >
            <x-modal name="finding-lifecycle" :show="old('_modal') === 'finding-lifecycle'" maxWidth="md" focusable>
                <div class="p-6">
                    <h3 id="finding-lifecycle-heading" class="text-lg font-medium text-gray-900">Mark false positive</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Finding: <span id="finding-lifecycle-title" class="font-medium text-gray-900"></span>
                    </p>
                    <p class="mt-2 text-sm text-gray-500">A reason is required and will be stored in the audit log permanently.</p>

                    <form id="finding-lifecycle-form" method="POST" action="#" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="_modal" value="finding-lifecycle">

                        <div>
                            <x-input-label for="finding_reason" value="Reason" />
                            <textarea
                                id="finding_reason"
                                name="reason"
                                rows="4"
                                required
                                minlength="10"
                                maxlength="2000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >{{ old('reason') }}</textarea>
                            <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'finding-lifecycle')">
                                Cancel
                            </x-secondary-button>
                            <x-primary-button id="finding-lifecycle-submit" type="submit">Confirm</x-primary-button>
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
    const trigger = event.target.closest('[data-finding-detail]');
    if (!trigger) return;

    const id = trigger.getAttribute('data-finding-detail');
    const body = document.getElementById('finding-detail-body');
    body.innerHTML = '<p class="text-sm text-gray-500">Loading…</p>';
    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'finding-detail' }));

    try {
        const response = await fetch(`{{ url('/admin/findings') }}/${id}`, {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        body.innerHTML = `
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">${data.title}</h3>
                    <p class="mt-1 text-sm text-gray-600">${data.repository ?? 'N/A'}</p>
                </div>
                <button type="button" class="text-sm text-gray-500 hover:text-gray-800" onclick="window.dispatchEvent(new CustomEvent('close-modal', { detail: 'finding-detail' }))">Close</button>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 text-sm">
                <div class="min-w-0"><dt class="text-gray-500">Severity</dt><dd class="font-medium text-gray-900">${data.severity}</dd></div>
                <div class="min-w-0"><dt class="text-gray-500">Status</dt><dd class="font-medium text-gray-900">${data.status}</dd></div>
                <div class="min-w-0"><dt class="text-gray-500">Location</dt><dd class="font-mono text-xs text-gray-900 break-all">${data.file_path}:${data.line_number ?? 'N/A'}</dd></div>
                <div class="min-w-0"><dt class="text-gray-500">Rule</dt><dd class="font-medium text-gray-900 break-all">${data.rule_id ?? 'N/A'}</dd></div>
                <div class="min-w-0"><dt class="text-gray-500">Objective</dt><dd class="font-medium text-gray-900">${data.objective ?? 'N/A'}</dd></div>
                <div class="min-w-0"><dt class="text-gray-500">Fingerprint</dt><dd class="font-mono text-xs text-gray-900 break-all">${data.fingerprint ?? 'N/A'}</dd></div>
            </dl>
            <div class="mt-4">
                <h4 class="text-sm font-semibold text-gray-900">Evidence</h4>
                <p class="mt-1 text-sm text-gray-700 whitespace-pre-wrap">${data.evidence || ''}</p>
            </div>
            <div class="mt-4">
                <h4 class="text-sm font-semibold text-gray-900">Recommendation</h4>
                <p class="mt-1 text-sm text-gray-700 whitespace-pre-wrap">${data.recommendation || 'N/A'}</p>
            </div>
        `;
    } catch (error) {
        body.innerHTML = '<p class="text-sm text-red-600">Unable to load finding details.</p>';
    }
});
</script>
@endpush
