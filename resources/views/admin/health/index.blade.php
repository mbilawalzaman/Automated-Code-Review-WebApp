@extends('layouts.admin')

@section('title', 'System Health')
@section('page-title', 'System Health & Operations')

@section('content')
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Real-Time Operational Health</h2>
            <p class="mt-1 text-sm text-gray-600">OPS-001 Executive health status monitoring worker nodes, review queue, database, scheduler, backups, and external integrations.</p>
        </div>

        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-500">Auto-refreshes every 30 seconds</span>
            <span class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-semibold shadow-xs ring-1 ring-inset {{ $metrics['overall_status'] === 'Healthy' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20' }}">
                System: {{ $metrics['overall_status'] }}
            </span>
        </div>
    </div>

    {{-- System Core Components Grid --}}
    <div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <div class="overflow-hidden rounded-lg bg-white p-5 shadow">
            <dt class="truncate text-xs font-medium uppercase tracking-wider text-gray-500">Database Engine</dt>
            <dd class="mt-2 flex items-center justify-between">
                <span class="text-xl font-bold text-gray-900">{{ $metrics['system']['database'] }}</span>
                <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold bg-green-100 text-green-800">MySQL / SQLite</span>
            </dd>
        </div>

        <div class="overflow-hidden rounded-lg bg-white p-5 shadow">
            <dt class="truncate text-xs font-medium uppercase tracking-wider text-gray-500">Storage Disk</dt>
            <dd class="mt-2 flex items-center justify-between">
                <span class="text-xl font-bold text-gray-900">{{ $metrics['system']['storage'] }}</span>
                <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold bg-blue-100 text-blue-800">storage/app</span>
            </dd>
        </div>

        <div class="overflow-hidden rounded-lg bg-white p-5 shadow">
            <dt class="truncate text-xs font-medium uppercase tracking-wider text-gray-500">Scheduler Heartbeat</dt>
            <dd class="mt-2 flex items-center justify-between">
                <span class="text-sm font-bold text-gray-900 truncate">{{ $metrics['system']['scheduler'] }}</span>
                <span class="text-xs text-gray-500">{{ $metrics['system']['scheduler_last_seen'] }}</span>
            </dd>
        </div>

        <div class="overflow-hidden rounded-lg bg-white p-5 shadow">
            <dt class="truncate text-xs font-medium uppercase tracking-wider text-gray-500">Failed Queue Jobs</dt>
            <dd class="mt-2 flex items-center justify-between">
                <span class="text-xl font-bold text-gray-900">{{ $metrics['system']['failed_jobs_count'] }}</span>
                <span class="text-xs text-gray-500">failed_jobs table</span>
            </dd>
        </div>
    </div>

    {{-- Worker Nodes Section --}}
    <div class="mb-6 rounded-lg bg-white shadow overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 text-sm">Review Worker Nodes</h3>
            <span class="text-xs text-gray-500">Expects outbound Mac Mini polling via <code class="bg-gray-100 px-1 py-0.5 rounded font-mono">POST /api/v1/worker/heartbeat</code></span>
        </div>
        <div class="overflow-x-auto min-w-full">
            <table class="w-full divide-y divide-gray-200 text-left text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 font-semibold uppercase tracking-wider text-xs text-gray-500">Worker ID</th>
                        <th scope="col" class="px-6 py-3 font-semibold uppercase tracking-wider text-xs text-gray-500">Status</th>
                        <th scope="col" class="px-6 py-3 font-semibold uppercase tracking-wider text-xs text-gray-500">Last Heartbeat</th>
                        <th scope="col" class="px-6 py-3 font-semibold uppercase tracking-wider text-xs text-gray-500">Environment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($metrics['workers'] as $worker)
                        <tr class="hover:bg-gray-50/50">
                            <td class="px-6 py-4 font-mono text-xs font-medium text-gray-900">
                                {{ $worker['worker_id'] }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold border {{ $worker['status_class'] }}">
                                    {{ $worker['status'] }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-600 text-xs">
                                {{ $worker['last_ping_formatted'] }}
                            </td>
                            <td class="px-6 py-4 text-gray-500 text-xs font-mono">
                                @if (isset($worker['hostname']) && $worker['hostname'])
                                    {{ $worker['hostname'] }} @if(isset($worker['os_info'])) ({{ $worker['os_info'] }}) @endif
                                @else
                                    <span class="text-gray-400">N/A</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-6 text-center text-sm text-gray-500">
                                No review worker heartbeats recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Queue & Integrations Grid --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Queue Depth & Stuck Jobs --}}
        <div class="rounded-lg bg-white shadow p-6">
            <h3 class="font-semibold text-gray-900 text-sm mb-4">Review Queue & Sweeper Health</h3>
            <dl class="grid grid-cols-2 gap-4">
                <div class="rounded-md bg-gray-50 p-4 border border-gray-100">
                    <dt class="text-xs text-gray-500 uppercase font-medium">Pending Jobs</dt>
                    <dd class="mt-1 text-2xl font-bold text-gray-900">{{ $metrics['queue']['pending'] }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 border border-gray-100">
                    <dt class="text-xs text-gray-500 uppercase font-medium">Processing Jobs</dt>
                    <dd class="mt-1 text-2xl font-bold text-gray-900">{{ $metrics['queue']['processing'] }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 border border-gray-100">
                    <dt class="text-xs text-gray-500 uppercase font-medium">Oldest Pending Age</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $metrics['queue']['oldest_pending_age'] }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 border border-gray-100">
                    <dt class="text-xs text-gray-500 uppercase font-medium">Stuck Jobs (>30m)</dt>
                    <dd class="mt-1 text-2xl font-bold {{ $metrics['queue']['stuck_jobs'] > 0 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ $metrics['queue']['stuck_jobs'] }}
                    </dd>
                </div>
            </dl>

            @if (! empty($metrics['queue']['jobs']))
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-xs">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-3">Job</th>
                                <th class="py-2 pr-3">Repo</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach ($metrics['queue']['jobs'] as $job)
                                <tr>
                                    <td class="py-2 pr-3 font-mono">#{{ $job['id'] }} {{ $job['commit_sha'] }}</td>
                                    <td class="py-2 pr-3">{{ $job['repository'] }}</td>
                                    <td class="py-2 pr-3">
                                        {{ $job['status'] }}
                                        @if ($job['is_stuck'])
                                            <span class="ml-1 text-red-600 font-semibold">stuck</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right">
                                        @can('jobs.cancel')
                                            <form method="POST" action="{{ route('admin.review-jobs.cancel', $job['id']) }}" class="inline" onsubmit="return confirm('Cancel review job #{{ $job['id'] }}?');">
                                                @csrf
                                                <input type="hidden" name="reason" value="Cancelled from system health UI">
                                                <button type="submit" class="font-semibold text-red-600 hover:text-red-800">Cancel</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Integrations Status --}}
        <div class="rounded-lg bg-white shadow p-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 text-sm">External Integrations (live probes)</h3>
                @can('integrations.manage')
                    <a href="{{ route('admin.integrations.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">Manage credentials</a>
                @endcan
            </div>
            <ul class="divide-y divide-gray-100">
                @foreach ($metrics['integrations'] as $key => $integration)
                    <li class="py-3 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $integration['label'] }}</p>
                            @if (! empty($integration['detail']))
                                <p class="text-xs text-gray-500">{{ $integration['detail'] }}</p>
                            @endif
                        </div>
                        <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold {{ $integration['status_class'] }}">
                            {{ $integration['status_label'] }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        setTimeout(() => window.location.reload(), 30000);
    </script>
@endpush
