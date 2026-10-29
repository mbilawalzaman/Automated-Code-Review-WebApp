@props([
    'paginator',
    'label' => 'records',
])

@php
    $perPageOptions = [10, 25, 50, 100];
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
    $startPage = max(1, min($currentPage - 2, max(1, $lastPage - 4)));
    $endPage = max(1, min($lastPage, max(5, $currentPage + 2)));
@endphp

<div class="flex flex-col gap-4 border-t border-gray-200 px-6 py-4 text-sm text-gray-600 md:flex-row md:items-center md:justify-between">
    <div class="flex flex-wrap items-center gap-4">
        <div>
            Showing
            <span class="font-medium text-gray-900">{{ $paginator->firstItem() ?? 0 }}</span>
            to
            <span class="font-medium text-gray-900">{{ $paginator->lastItem() ?? 0 }}</span>
            of
            <span class="font-medium text-gray-900">{{ $paginator->total() }}</span>
            {{ $label }}
        </div>

        <div class="hidden h-8 border-l border-gray-200 md:block"></div>

        <form method="GET" class="flex items-center gap-2">
            @foreach (request()->except(['per_page', 'page']) as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach

            <span class="text-xs">Show</span>
            <select
                name="per_page"
                class="rounded-md border-gray-300 py-1 pl-3 pr-8 text-sm focus:border-gray-900 focus:ring-gray-900"
                onchange="this.form.submit()"
            >
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}" @selected($paginator->perPage() === $option)>{{ $option }}</option>
                @endforeach
            </select>
            <span class="text-xs">entries</span>
        </form>
    </div>

    <nav class="flex items-center gap-2" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="rounded-md border border-gray-200 px-3 py-2 text-gray-400">Previous</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="rounded-md border border-gray-300 px-3 py-2 text-gray-700 hover:bg-gray-50">Previous</a>
        @endif

        @for ($page = $startPage; $page <= $endPage; $page++)
            @if ($page === $currentPage)
                <span class="rounded-md bg-indigo-600 px-3 py-2 font-medium text-white">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="rounded-md border border-gray-300 px-3 py-2 text-gray-700 hover:bg-gray-50">{{ $page }}</a>
            @endif
        @endfor

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="rounded-md border border-gray-300 px-3 py-2 text-gray-700 hover:bg-gray-50">Next</a>
        @else
            <span class="rounded-md border border-gray-200 px-3 py-2 text-gray-400">Next</span>
        @endif
    </nav>
</div>
