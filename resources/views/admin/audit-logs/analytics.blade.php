@extends('layouts.admin')

@section('title', 'Audit Analytics')
@section('page-title', 'Audit Analytics')

@section('content')
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-base font-semibold text-gray-900">Audit Analytics & Operations Dashboard</h2>
        <p class="mt-1 text-sm text-gray-600">Real-time system telemetry, security event alerts, worker engine status, and audit trends.</p>
    </div>
    <div class="flex items-center space-x-3">
        <a href="{{ route('admin.audit-logs.export', ['format' => 'csv']) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">
            <svg class="-ml-1 mr-2 h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Export CSV
        </a>
    </div>
</div>

<!-- KPI Metric Cards Grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
    <!-- Card 1: Total Events -->
    <div class="bg-white rounded-lg p-6 shadow border border-gray-200">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Total Audit Events</span>
            <div class="p-2 bg-indigo-50 text-indigo-600 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
            </div>
        </div>
        <div class="mt-3 flex items-baseline justify-between">
            <span class="text-2xl font-bold text-gray-900">{{ number_format($analytics['kpi']['total_events']) }}</span>
            <span class="text-xs text-gray-500">{{ $analytics['kpi']['events_24h'] }} in last 24h</span>
        </div>
    </div>

    <!-- Card 2: Failed Logins 24h -->
    <div class="bg-white rounded-lg p-6 shadow border border-gray-200">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Failed Logins (24h)</span>
            <div class="p-2 bg-amber-50 text-amber-600 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
        </div>
        <div class="mt-3 flex items-baseline justify-between">
            <span class="text-2xl font-bold text-gray-900">{{ number_format($analytics['kpi']['failed_logins_24h']) }}</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $analytics['kpi']['failed_logins_24h'] > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600' }}">
                {{ $analytics['kpi']['failed_logins_24h'] > 0 ? 'Warning' : 'Normal' }}
            </span>
        </div>
    </div>

    <!-- Card 3: Security Alerts 24h -->
    <div class="bg-white rounded-lg p-6 shadow border border-gray-200">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Security Alerts (24h)</span>
            <div class="p-2 bg-red-50 text-red-600 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
        </div>
        <div class="mt-3 flex items-baseline justify-between">
            <span class="text-2xl font-bold text-gray-900">{{ number_format($analytics['kpi']['security_alerts_24h']) }}</span>
            <span class="text-xs text-gray-500">Unauthorized attempts</span>
        </div>
    </div>

    <!-- Card 4: Review Overrides -->
    <div class="bg-white rounded-lg p-6 shadow border border-gray-200">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Review Overrides</span>
            <div class="p-2 bg-purple-50 text-purple-600 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
            </div>
        </div>
        <div class="mt-3 flex items-baseline justify-between">
            <span class="text-2xl font-bold text-gray-900">{{ number_format($analytics['kpi']['review_overrides']) }}</span>
            <span class="text-xs text-gray-500">Admin manual overrides</span>
        </div>
    </div>
</div>

<!-- Data-Driven Worker System Health Widget -->
<div class="bg-white rounded-lg p-6 shadow border border-gray-200 mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 mb-4 border-b border-gray-200 gap-2">
        <div class="flex items-center space-x-3">
            <h3 class="text-base font-semibold text-gray-900">Mac Worker Engine System Health</h3>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $analytics['worker_health']['status_badge'] }}">
                {{ $analytics['worker_health']['status_label'] }}
            </span>
        </div>
        <span class="text-xs text-gray-500">Last Polled: <span class="font-medium text-gray-900">{{ $analytics['worker_health']['last_polled_at'] }}</span></span>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
            <span class="block text-gray-500 mb-1">Active Workers</span>
            <span class="text-lg font-bold text-gray-900">{{ $analytics['worker_health']['active_workers'] }} Connected</span>
        </div>
        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
            <span class="block text-gray-500 mb-1">Pending Job Queue</span>
            <span class="text-lg font-bold text-gray-900">{{ $analytics['worker_health']['pending_queue'] }} Jobs</span>
        </div>
        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
            <span class="block text-gray-500 mb-1">Processing Queue</span>
            <span class="text-lg font-bold text-gray-900">{{ $analytics['worker_health']['processing_queue'] }} Jobs</span>
        </div>
        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
            <span class="block text-gray-500 mb-1">Avg Execution Duration</span>
            <span class="text-lg font-bold text-gray-900">{{ $analytics['worker_health']['avg_duration'] }}</span>
        </div>
    </div>
</div>

<!-- Interactive ApexCharts Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Chart 1: Daily Trend Line Chart (Span 2 cols) -->
    <div class="lg:col-span-2 bg-white rounded-lg p-6 shadow border border-gray-200">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-gray-900">Daily Audit Event Trend (30 Days)</h3>
            <span class="text-xs text-gray-500">System Activity</span>
        </div>
        <div id="chart-daily-trend" class="w-full h-64"></div>
    </div>

    <!-- Chart 2: Category Distribution Donut Chart -->
    <div class="bg-white rounded-lg p-6 shadow border border-gray-200">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-gray-900">Category Distribution</h3>
            <span class="text-xs text-gray-500">By Domain</span>
        </div>
        <div id="chart-categories" class="w-full h-64 flex items-center justify-center"></div>
    </div>
</div>

<!-- Chart 3: Severity Breakdown -->
<div class="bg-white rounded-lg p-6 shadow border border-gray-200 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-base font-semibold text-gray-900">Event Severity Distribution</h3>
        <span class="text-xs text-gray-500">Info / Warning / Critical</span>
    </div>
    <div id="chart-severities" class="w-full h-48"></div>
</div>

<!-- Recent Security Activity Stream Table -->
<div class="bg-white rounded-lg shadow border border-gray-200 p-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-base font-semibold text-gray-900">Recent Security & Authentication Activity Stream</h3>
            <p class="text-xs text-gray-500 mt-0.5">Live security event log stream for authentication and access control.</p>
        </div>
        <a href="{{ route('admin.audit-logs.index') }}" class="text-xs font-semibold text-indigo-600 hover:underline">View All Ledger Events &rarr;</a>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-4 py-3 font-semibold text-gray-500 uppercase tracking-wide">Action & IP</th>
                    <th scope="col" class="px-4 py-3 font-semibold text-gray-500 uppercase tracking-wide">Category</th>
                    <th scope="col" class="px-4 py-3 font-semibold text-gray-500 uppercase tracking-wide">Severity</th>
                    <th scope="col" class="px-4 py-3 font-semibold text-gray-500 uppercase tracking-wide">Actor</th>
                    <th scope="col" class="px-4 py-3 font-semibold text-gray-500 uppercase tracking-wide">Timestamp</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($analytics['recent_security_events'] as $event)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $event->action }}</div>
                            <div class="text-gray-500">{{ $event->ip_address ?? '127.0.0.1' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-gray-100 text-gray-800">
                                {{ $event->category instanceof \App\Enums\AuditCategory ? $event->category->label() : ucfirst((string) $event->category) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $sev = $event->severity instanceof \App\Enums\AuditSeverity ? $event->severity : \App\Enums\AuditSeverity::Info;
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium {{ $sev->badgeClass() }}">
                                {{ $sev->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-medium text-gray-900">
                            {{ $event->user?->name ?? 'System' }}
                        </td>
                        <td class="px-4 py-3 text-gray-500">
                            {{ $event->created_at?->format('M d, Y H:i:s') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                            No security events recorded yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- ApexCharts Script Injection -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const chartData = @json($analytics['charts']);

        // 1. Daily Trend Line Chart
        const optionsTrend = {
            series: [{
                name: 'Audit Events',
                data: chartData.daily_trend.counts
            }],
            chart: {
                type: 'area',
                height: 250,
                toolbar: { show: false }
            },
            colors: ['#4f46e5'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            xaxis: {
                categories: chartData.daily_trend.dates
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.45,
                    opacityTo: 0.05
                }
            }
        };
        new ApexCharts(document.querySelector("#chart-daily-trend"), optionsTrend).render();

        // 2. Category Distribution Donut Chart
        const optionsCat = {
            series: chartData.categories.series.length > 0 ? chartData.categories.series : [1],
            labels: chartData.categories.labels.length > 0 ? chartData.categories.labels : ['No Data'],
            chart: {
                type: 'donut',
                height: 250
            },
            colors: ['#4f46e5', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'],
            legend: { position: 'bottom' }
        };
        new ApexCharts(document.querySelector("#chart-categories"), optionsCat).render();

        // 3. Severity Breakdown Bar Chart
        const optionsSev = {
            series: [{
                name: 'Events Count',
                data: chartData.severities.series
            }],
            chart: {
                type: 'bar',
                height: 180,
                toolbar: { show: false }
            },
            colors: ['#3b82f6', '#f59e0b', '#ef4444'],
            plotOptions: {
                bar: {
                    distributed: true,
                    borderRadius: 4,
                    columnWidth: '40%'
                }
            },
            xaxis: {
                categories: chartData.severities.labels
            },
            legend: { show: false }
        };
        new ApexCharts(document.querySelector("#chart-severities"), optionsSev).render();
    });
</script>
@endsection
