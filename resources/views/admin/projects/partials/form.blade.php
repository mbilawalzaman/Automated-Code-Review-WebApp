@php
    $idPrefix = $idPrefix ?? 'project';
    $modalName = $modalName ?? null;
    $cancelUrl = $cancelUrl ?? route('admin.projects.index');
@endphp

<div x-data="{
    slug: '{{ addslashes(old('slug', $project?->slug ?? '')) }}',
    get isSlugFormatInvalid() {
        if (!this.slug || this.slug.trim() === '') return false;
        return !/^[a-zA-Z0-9_-]+$/.test(this.slug);
    },
    validateSlug() {
        const input = this.$refs.slugInput;
        if (!input) return;
        if (this.isSlugFormatInvalid) {
            input.setCustomValidity('The slug may only contain letters, numbers, dashes, and underscores.');
        } else {
            input.setCustomValidity('');
        }
    }
}"
x-init="$watch('slug', () => validateSlug())"
class="space-y-6">

    <div>
        <label for="{{ $idPrefix }}-name" class="block text-sm font-medium text-gray-700">Name</label>
        <input
            id="{{ $idPrefix }}-name"
            name="name"
            type="text"
            value="{{ old('name', $project?->name) }}"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 @error('name') border-red-300 text-red-900 focus:border-red-500 focus:ring-red-500 @enderror"
            required
        >
        @error('name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="{{ $idPrefix }}-slug" class="block text-sm font-medium text-gray-700">Slug</label>
        <input
            id="{{ $idPrefix }}-slug"
            name="slug"
            type="text"
            x-ref="slugInput"
            x-model="slug"
            value="{{ old('slug', $project?->slug) }}"
            class="mt-1 block w-full rounded-md shadow-sm sm:text-sm transition-colors @error('slug') border-red-300 text-red-900 focus:border-red-500 focus:ring-red-500 @enderror"
            :class="isSlugFormatInvalid ? 'border-red-300 text-red-900 focus:border-red-500 focus:ring-red-500' : (!@json($errors->has('slug')) ? 'border-gray-300 focus:border-gray-900 focus:ring-gray-900' : '')"
            placeholder="e.g. gamma-project"
        >
        <p x-show="isSlugFormatInvalid" x-cloak class="mt-1 text-xs text-red-600 font-medium">
            The slug may only contain letters, numbers, dashes (-), and underscores (_). No spaces or special characters.
        </p>
        <p x-show="!isSlugFormatInvalid && !@json($errors->has('slug'))" class="mt-1 text-xs text-gray-500">
            Allowed: letters, numbers, dashes (-), and underscores (_). Leave blank to generate automatically from project name.
        </p>
        @error('slug')
            <p class="mt-1 text-sm text-red-600 font-medium">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="{{ $idPrefix }}-description" class="block text-sm font-medium text-gray-700">Description</label>
        <textarea
            id="{{ $idPrefix }}-description"
            name="description"
            rows="4"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 @error('description') border-red-300 text-red-900 focus:border-red-500 focus:ring-red-500 @enderror"
        >{{ old('description', $project?->description) }}</textarea>
        @error('description')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <label class="flex items-center gap-2">
        <input
            type="checkbox"
            name="is_active"
            value="1"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
            @checked(old('is_active', $project?->is_active ?? true))
        >
        <span class="text-sm font-medium text-gray-700">Active</span>
    </label>

    <div class="flex items-center justify-end gap-3 border-t border-gray-200 pt-6">
        @if ($modalName)
            <button type="button" x-on:click="$dispatch('close-modal', '{{ $modalName }}')" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancel</button>
        @else
            <a href="{{ $cancelUrl }}" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancel</a>
        @endif
        <button
            type="submit"
            :disabled="isSlugFormatInvalid"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-gray-800"
            :class="isSlugFormatInvalid ? 'opacity-50 cursor-not-allowed' : ''"
        >
            {{ $submitLabel }}
        </button>
    </div>
</div>
