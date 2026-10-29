<x-admin.actions-dropdown>
    <button
        type="button"
        class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
        data-review-detail="{{ $review->id }}"
        x-on:click="open = false"
    >
        View
    </button>

    @if (
        $canOverride
        && $review->status === \App\Enums\ReviewStatus::Failed
        && $review->overridden_at === null
    )
        <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-red-700 hover:bg-red-50"
            x-on:click="
                window.dispatchEvent(new CustomEvent('review-override', {
                    detail: {
                        url: @js(route('admin.reviews.override', $review)),
                        title: @js('PR #' . ($review->pull_request_number ?? $review->id))
                    }
                }));
                open = false;
            "
        >
            Override
        </button>
    @endif
</x-admin.actions-dropdown>
