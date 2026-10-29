<x-admin.actions-dropdown>
    <a
        href="{{ route('admin.reports.stream', $report->id) }}"
        target="_blank"
        class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 flex items-center"
        x-on:click="open = false"
    >
        <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        </svg>
        View PDF
    </a>

    <a
        href="{{ route('admin.reports.download', $report->id) }}"
        class="block w-full px-4 py-2 text-left text-sm text-indigo-600 hover:bg-indigo-50 flex items-center font-medium"
        x-on:click="open = false"
    >
        <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Download PDF
    </a>
</x-admin.actions-dropdown>
