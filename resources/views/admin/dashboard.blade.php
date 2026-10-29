@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<!-- Header Welcome Banner -->
<div class="bg-white rounded-lg shadow p-6 border border-gray-200 mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-gray-900">Welcome, {{ auth()->user()->name }}</h2>
        <p class="text-sm text-gray-600 mt-1">Automated Code Review Platform & Executive Management Dashboard.</p>
    </div>
    <div class="flex items-center space-x-2">
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
            System Status: Healthy
        </span>
    </div>
</div>

<!-- Core Platform Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow p-5 border border-gray-200 flex items-center justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Projects</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($summary['core']['projects']) }}</p>
        </div>
        <div class="p-3 bg-indigo-50 text-indigo-600 rounded-lg">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
            </svg>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-5 border border-gray-200 flex items-center justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Repositories</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($summary['core']['repositories']) }}</p>
        </div>
        <div class="p-3 bg-cyan-50 text-cyan-600 rounded-lg">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-5 border border-gray-200 flex items-center justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Reviews</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($summary['core']['reviews']) }}</p>
        </div>
        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-lg">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-5 border border-gray-200 flex items-center justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Findings</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($summary['core']['findings']) }}</p>
        </div>
        <div class="p-3 bg-amber-50 text-amber-600 rounded-lg">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
    </div>
</div>

<!-- Executive Domain Widgets Section -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Widget 1: Security & Audit -->
    <div class="bg-white rounded-lg shadow border border-gray-200 p-6 flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between pb-3 mb-4 border-b border-gray-200">
                <div class="flex items-center space-x-2">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <h3 class="text-base font-semibold text-gray-900">Security & Audit</h3>
                </div>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">
                    Security
                </span>
            </div>

            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Audit Events (Today)</span>
                    <span class="font-bold text-gray-900">{{ number_format($summary['security_audit']['audit_events_today']) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Failed Logins (24h)</span>
                    <span class="font-bold {{ $summary['security_audit']['failed_logins_24h'] > 0 ? 'text-amber-600' : 'text-gray-900' }}">
                        {{ number_format($summary['security_audit']['failed_logins_24h']) }}
                    </span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Critical Security Alerts</span>
                    <span class="font-bold {{ $summary['security_audit']['critical_alerts'] > 0 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ number_format($summary['security_audit']['critical_alerts']) }}
                    </span>
                </div>
            </div>
        </div>

        <div class="mt-6 pt-4 border-t border-gray-200">
            <a href="{{ route('admin.audit-logs.analytics') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center justify-between group">
                <span>View Audit Analytics</span>
                <span class="group-hover:translate-x-1 transition-transform">&rarr;</span>
            </a>
        </div>
    </div>

    <!-- Widget 2: Worker Engine -->
    <div class="bg-white rounded-lg shadow border border-gray-200 p-6 flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between pb-3 mb-4 border-b border-gray-200">
                <div class="flex items-center space-x-2">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <h3 class="text-base font-semibold text-gray-900">Worker Engine</h3>
                </div>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border {{ $summary['worker_engine']['status_class'] }}">
                    {{ $summary['worker_engine']['status_label'] }}
                </span>
            </div>

            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Worker Status</span>
                    <span class="font-bold text-gray-900">{{ $summary['worker_engine']['status_label'] }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Pending Review Jobs</span>
                    <span class="font-bold text-gray-900">{{ number_format($summary['worker_engine']['pending_jobs']) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Jobs Processed Today</span>
                    <span class="font-bold text-gray-900">{{ number_format($summary['worker_engine']['jobs_processed_today']) }}</span>
                </div>
            </div>
        </div>

        <div class="mt-6 pt-4 border-t border-gray-200">
            <a href="{{ route('admin.reviews.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center justify-between group">
                <span>View Review Queue</span>
                <span class="group-hover:translate-x-1 transition-transform">&rarr;</span>
            </a>
        </div>
    </div>

    <!-- Widget 3: Code Review & Findings -->
    <div class="bg-white rounded-lg shadow border border-gray-200 p-6 flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between pb-3 mb-4 border-b border-gray-200">
                <div class="flex items-center space-x-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <h3 class="text-base font-semibold text-gray-900">Code Review</h3>
                </div>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                    Quality
                </span>
            </div>

            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Reviews Pending</span>
                    <span class="font-bold text-gray-900">{{ number_format($summary['code_review']['reviews_pending']) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Reviews Completed Today</span>
                    <span class="font-bold text-gray-900">{{ number_format($summary['code_review']['reviews_completed_today']) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-600">Critical / High Findings</span>
                    <span class="font-bold {{ $summary['code_review']['critical_high_findings'] > 0 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ number_format($summary['code_review']['critical_high_findings']) }}
                    </span>
                </div>
            </div>
        </div>

        <div class="mt-6 pt-4 border-t border-gray-200">
            <a href="{{ route('admin.findings.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center justify-between group">
                <span>View Security Findings</span>
                <span class="group-hover:translate-x-1 transition-transform">&rarr;</span>
            </a>
        </div>
    </div>

</div>

{{-- Trend analytics (UI-004) --}}
<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <div class="rounded-lg bg-white p-6 shadow border border-gray-200">
        <h3 class="text-sm font-semibold text-gray-900">Security findings by severity</h3>
        <p class="mt-1 text-xs text-gray-500">Daily opened findings over the last 14 days</p>
        <div id="chart-security-findings" class="mt-4"></div>
    </div>
    <div class="rounded-lg bg-white p-6 shadow border border-gray-200">
        <h3 class="text-sm font-semibold text-gray-900">Changed-code coverage</h3>
        <p class="mt-1 text-xs text-gray-500">Average coverage % on completed reviews</p>
        <div id="chart-coverage" class="mt-4"></div>
    </div>
    <div class="rounded-lg bg-white p-6 shadow border border-gray-200">
        <h3 class="text-sm font-semibold text-gray-900">Review failure rate</h3>
        <p class="mt-1 text-xs text-gray-500">Failed / review_error share of completed reviews</p>
        <div id="chart-failure-rate" class="mt-4"></div>
    </div>
    <div class="rounded-lg bg-white p-6 shadow border border-gray-200">
        <h3 class="text-sm font-semibold text-gray-900">Finding resolution velocity</h3>
        <p class="mt-1 text-xs text-gray-500">Opened vs resolved / accepted / false-positive</p>
        <div id="chart-resolution" class="mt-4"></div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const trends = @json($trends ?? ['labels' => []]);
        const labels = trends.labels || [];

        const security = trends.security_findings || {};
        new ApexCharts(document.querySelector('#chart-security-findings'), {
            chart: { type: 'bar', stacked: true, height: 280, toolbar: { show: false } },
            series: [
                { name: 'Critical', data: security.critical || [] },
                { name: 'High', data: security.high || [] },
                { name: 'Medium', data: security.medium || [] },
                { name: 'Low', data: security.low || [] },
            ],
            xaxis: { categories: labels, labels: { rotate: -45 } },
            colors: ['#b91c1c', '#ea580c', '#ca8a04', '#64748b'],
            legend: { position: 'top' },
            dataLabels: { enabled: false },
        }).render();

        new ApexCharts(document.querySelector('#chart-coverage'), {
            chart: { type: 'line', height: 280, toolbar: { show: false } },
            series: [{ name: 'Coverage %', data: trends.coverage || [] }],
            xaxis: { categories: labels, labels: { rotate: -45 } },
            stroke: { curve: 'smooth', width: 3 },
            colors: ['#0f766e'],
            yaxis: { min: 0, max: 100, title: { text: '%' } },
            markers: { size: 3 },
        }).render();

        new ApexCharts(document.querySelector('#chart-failure-rate'), {
            chart: { type: 'line', height: 280, toolbar: { show: false } },
            series: [{ name: 'Failure %', data: (trends.failure_rates && trends.failure_rates.rate) || [] }],
            xaxis: { categories: labels, labels: { rotate: -45 } },
            stroke: { curve: 'smooth', width: 3 },
            colors: ['#dc2626'],
            yaxis: { min: 0, max: 100, title: { text: '%' } },
        }).render();

        const resolution = trends.resolution || {};
        new ApexCharts(document.querySelector('#chart-resolution'), {
            chart: { type: 'bar', height: 280, toolbar: { show: false } },
            series: [
                { name: 'Opened', data: resolution.opened || [] },
                { name: 'Resolved', data: resolution.resolved || [] },
            ],
            xaxis: { categories: labels, labels: { rotate: -45 } },
            colors: ['#2563eb', '#16a34a'],
            legend: { position: 'top' },
            dataLabels: { enabled: false },
        }).render();
    });
</script>
@endpush
