@if ($canManage && $finding->status === \App\Enums\FindingStatus::Open)
    <x-admin.actions-dropdown>
        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-amber-700 hover:bg-amber-50"
            x-on:click="
                window.dispatchEvent(new CustomEvent('finding-lifecycle', {
                    detail: {
                        mode: 'false-positive',
                        url: @js(route('admin.findings.false-positive', $finding)),
                        title: @js($finding->title)
                    }
                }));
                open = false;
            "
        >
            False positive
        </button>

        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-blue-700 hover:bg-blue-50"
            x-on:click="
                window.dispatchEvent(new CustomEvent('finding-lifecycle', {
                    detail: {
                        mode: 'accept',
                        url: @js(route('admin.findings.accept', $finding)),
                        title: @js($finding->title)
                    }
                }));
                open = false;
            "
        >
            Accept
        </button>
    </x-admin.actions-dropdown>
@else
    <span class="text-xs text-gray-400">—</span>
@endif
