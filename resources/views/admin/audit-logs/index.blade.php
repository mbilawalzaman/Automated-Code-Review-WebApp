@extends('layouts.admin')

@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')

@section('content')
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-base font-semibold text-gray-900">Enterprise Event Ledger</h2>
        <p class="mt-1 text-sm text-gray-600">Immutable append-only audit trail capturing security, authentication, RBAC, and system events.</p>
    </div>
    <div class="flex items-center space-x-3">
        <a href="{{ route('admin.audit-logs.export', ['format' => 'csv']) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">
            <svg class="-ml-1 mr-2 h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Export CSV
        </a>
        <a href="{{ route('admin.audit-logs.export', ['format' => 'json']) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">
            <svg class="-ml-1 mr-2 h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Export JSON
        </a>
    </div>
</div>

<!-- Filter Box -->
<div class="mb-6 rounded-lg bg-white p-4 sm:p-6 shadow border border-gray-200">
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <div>
            <label for="filter-category" class="block text-xs font-semibold uppercase tracking-wide text-gray-700 mb-1">Category</label>
            <select id="filter-category" class="w-full rounded-md border-gray-300 text-sm font-medium text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 bg-white">
                <option value="">All Categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-severity" class="block text-xs font-semibold uppercase tracking-wide text-gray-700 mb-1">Severity</label>
            <select id="filter-severity" class="w-full rounded-md border-gray-300 text-sm font-medium text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 bg-white">
                <option value="">All Severities</option>
                @foreach($severities as $sev)
                    <option value="{{ $sev->value }}">{{ $sev->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-date-from" class="block text-xs font-semibold uppercase tracking-wide text-gray-700 mb-1">Date From</label>
            <input type="date" id="filter-date-from" class="w-full rounded-md border-gray-300 text-sm font-medium text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 bg-white">
        </div>
        <div>
            <label for="filter-date-to" class="block text-xs font-semibold uppercase tracking-wide text-gray-700 mb-1">Date To</label>
            <input type="date" id="filter-date-to" class="w-full rounded-md border-gray-300 text-sm font-medium text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 bg-white">
        </div>
    </div>
</div>

<!-- DataTables Card -->
<div class="rounded-lg bg-white shadow p-4 sm:p-6 border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto min-w-full">
        <table
            id="audit-logs-table"
            data-datatable
            data-ajax-url="{{ route('admin.audit-logs.data') }}"
            data-columns='[
                {"data": "action_formatted", "name": "action"},
                {"data": "category_badge", "name": "category"},
                {"data": "severity_badge", "name": "severity"},
                {"data": "actor", "name": "user.name"},
                {"data": "target", "name": "target_type"},
                {"data": "created_at_formatted", "name": "created_at"},
                {"data": "actions", "orderable": false, "searchable": false}
            ]'
            class="w-full divide-y divide-gray-200 text-gray-900 min-w-[700px]"
        >
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="w-4/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Action & IP</th>
                    <th scope="col" class="w-2/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Category</th>
                    <th scope="col" class="w-20 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Severity</th>
                    <th scope="col" class="w-2/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Actor</th>
                    <th scope="col" class="w-2/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Target</th>
                    <th scope="col" class="w-32 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Timestamp</th>
                    <th scope="col" class="w-20 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Details</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<!-- Enterprise Standardized Modal -->
<x-modal name="audit-detail-modal" maxWidth="2xl">
    <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
        <div>
            <div class="flex items-center space-x-2">
                <span id="modal-audit-ref" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-semibold bg-gray-100 text-gray-800">AUD-000000</span>
                <h3 class="text-base font-semibold text-gray-900" id="modal-audit-action">Audit Log Entry</h3>
            </div>
            <p class="text-xs text-gray-500 mt-1" id="modal-audit-subtitle">Immutable Audit Event</p>
        </div>
        <button type="button" x-on:click="$dispatch('close-modal', 'audit-detail-modal')" class="text-gray-400 hover:text-gray-500 focus:outline-none">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="p-6 space-y-6 max-h-[75vh] overflow-y-auto">
        <!-- Section 1: Event Information -->
        <div>
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Event Information</h4>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200 text-xs">
                <div>
                    <span class="block font-medium text-gray-500">Actor</span>
                    <span class="font-semibold text-gray-900" id="modal-actor">-</span>
                </div>
                <div>
                    <span class="block font-medium text-gray-500">IP Address</span>
                    <span class="font-semibold text-gray-900" id="modal-ip">-</span>
                </div>
                <div>
                    <span class="block font-medium text-gray-500">Category</span>
                    <span class="font-semibold text-gray-900" id="modal-category">-</span>
                </div>
                <div>
                    <span class="block font-medium text-gray-500">Severity</span>
                    <span class="font-semibold text-gray-900" id="modal-severity">-</span>
                </div>
            </div>
        </div>

        <!-- Section 2: Reason / Justification (if present) -->
        <div id="modal-reason-container" class="hidden p-3 rounded-lg bg-amber-50 border border-amber-200 text-xs">
            <span class="block font-semibold text-amber-800 mb-1">Reason / Justification</span>
            <span class="text-amber-900" id="modal-reason">-</span>
        </div>

        <!-- Section 3: Before & After State Diff -->
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">State Changes</h4>
                <button type="button" onclick="copyAuditJson()" class="text-xs font-medium text-indigo-600 hover:underline">Copy Event JSON</button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-mono">
                <div class="p-3 rounded-lg bg-red-50 border border-red-200">
                    <span class="block font-semibold text-red-800 mb-2">Before State (-)</span>
                    <pre id="modal-before-state" class="whitespace-pre-wrap overflow-x-auto text-red-700">None</pre>
                </div>
                <div class="p-3 rounded-lg bg-green-50 border border-green-200">
                    <span class="block font-semibold text-green-800 mb-2">After State (+)</span>
                    <pre id="modal-after-state" class="whitespace-pre-wrap overflow-x-auto text-green-700">None</pre>
                </div>
            </div>
        </div>

        <!-- Section 4: Technical Context & Environment -->
        <div>
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Technical Context & Environment</h4>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200 text-xs">
                <div>
                    <span class="block font-medium text-gray-500">Target Entity</span>
                    <span class="font-semibold text-gray-900" id="modal-target">-</span>
                </div>
                <div>
                    <span class="block font-medium text-gray-500">Browser / OS</span>
                    <span class="font-semibold text-gray-900" id="modal-browser">-</span>
                </div>
                <div>
                    <span class="block font-medium text-gray-500">Request URI</span>
                    <span class="font-semibold text-gray-900" id="modal-request-uri">-</span>
                </div>
            </div>
        </div>

        <!-- Section 5: Raw JSON Toggle -->
        <div x-data="{ showRawJson: false }">
            <button type="button" x-on:click="showRawJson = !showRawJson" class="flex items-center justify-between w-full text-xs font-semibold text-gray-700 hover:text-gray-900 py-1">
                <span>Raw Audit JSON Payload</span>
                <span x-text="showRawJson ? 'Hide Raw JSON ▲' : 'Show Raw JSON ▼'" class="text-indigo-600"></span>
            </button>
            <pre x-show="showRawJson" id="modal-metadata" class="mt-2 p-3 rounded-lg bg-gray-900 text-gray-100 text-xs font-mono whitespace-pre-wrap overflow-x-auto">{}</pre>
        </div>
    </div>

    <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 flex justify-end">
        <button type="button" x-on:click="$dispatch('close-modal', 'audit-detail-modal')" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition">
            Close
        </button>
    </div>
</x-modal>

<script>
    let currentAuditData = null;

    function viewAuditDetails(id) {
        fetch('/admin/audit-logs/' + id)
            .then(res => res.json())
            .then(data => {
                currentAuditData = data;
                document.getElementById('modal-audit-ref').innerText = data.audit_ref;
                document.getElementById('modal-audit-action').innerText = data.action;
                document.getElementById('modal-audit-subtitle').innerText = 'Event Type: ' + data.event_type + ' | ' + data.created_at;
                document.getElementById('modal-actor').innerText = data.actor + ' (' + data.actor_email + ')';
                document.getElementById('modal-ip').innerText = data.ip_address || '127.0.0.1';
                document.getElementById('modal-category').innerText = data.category;
                document.getElementById('modal-severity').innerText = data.severity;

                document.getElementById('modal-target').innerText = data.target_type ? data.target_type + ' (ID ' + data.target_id + ')' : 'N/A';
                document.getElementById('modal-browser').innerText = data.browser_summary;
                document.getElementById('modal-request-uri').innerText = data.request_uri;

                // Reason container
                const reasonContainer = document.getElementById('modal-reason-container');
                if (data.reason) {
                    document.getElementById('modal-reason').innerText = data.reason;
                    reasonContainer.classList.remove('hidden');
                } else {
                    reasonContainer.classList.add('hidden');
                }

                document.getElementById('modal-before-state').innerText = data.before_state && Object.keys(data.before_state).length > 0 ? JSON.stringify(data.before_state, null, 2) : 'None';
                document.getElementById('modal-after-state').innerText = data.after_state && Object.keys(data.after_state).length > 0 ? JSON.stringify(data.after_state, null, 2) : 'None';

                document.getElementById('modal-metadata').innerText = JSON.stringify(data, null, 2);

                window.dispatchEvent(new CustomEvent('open-modal', { detail: 'audit-detail-modal' }));
            });
    }

    function copyAuditJson() {
        if (currentAuditData) {
            navigator.clipboard.writeText(JSON.stringify(currentAuditData, null, 2));
            alert('Audit log event JSON copied to clipboard!');
        }
    }
</script>
@endsection
