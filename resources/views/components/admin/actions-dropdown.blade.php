@props([
    'placement' => 'bottom',
])

<div
    x-data="{
        open: false,
        placement: @js($placement),
        menuStyle: '',
        ignoreScrollUntil: 0,
        menuStyleFor(rect) {
            const right = Math.max(8, window.innerWidth - rect.right);
            if (this.placement === 'top') {
                return `position:fixed; top:${Math.max(8, rect.top - 8)}px; right:${right}px; transform:translateY(-100%);`;
            }
            return `position:fixed; top:${rect.bottom + 8}px; right:${right}px;`;
        },
        openMenu() {
            const button = this.$refs.button;
            if (! button) {
                return;
            }
            this.menuStyle = this.menuStyleFor(button.getBoundingClientRect());
            this.ignoreScrollUntil = Date.now() + 150;
            this.open = true;
        },
        close() {
            this.open = false;
        },
        toggle() {
            if (this.open) {
                this.close();
            } else {
                this.openMenu();
            }
        },
        onWindowScroll() {
            if (! this.open || Date.now() < this.ignoreScrollUntil) {
                return;
            }
            this.close();
        },
    }"
    class="relative inline-block text-left"
    x-on:keydown.escape.window="close()"
    x-on:scroll.window.throttle.50ms="onWindowScroll()"
    x-on:resize.window="close()"
>
    <button
        type="button"
        x-ref="button"
        class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-900"
        aria-haspopup="true"
        x-bind:aria-expanded="open.toString()"
        x-on:click="toggle()"
    >
        <span>Actions</span>
        <svg class="h-4 w-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
        </svg>
    </button>
    <div
        x-cloak
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-bind:style="menuStyle"
        x-on:click.outside="close()"
        class="z-50 w-44 rounded-md border border-gray-200 bg-white py-1 text-left shadow-lg"
    >
        {{ $slot }}
    </div>
</div>
