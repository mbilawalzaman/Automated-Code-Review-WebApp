@extends('layouts.admin')

@section('title', 'SRS Revision')
@section('page-title', 'SRS Revision')

@section('content')
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 flex-1">
            <a href="{{ route('admin.srs.index') }}" class="text-xs font-medium text-gray-500 hover:text-gray-900">&larr; All revisions</a>
            <h2 class="mt-1 truncate text-base font-semibold text-gray-900"
                title="{{ $document->title }} — v{{ $document->version }}">{{ $document->title }} — v{{ $document->version }}</h2>
            <p class="mt-1 truncate text-sm text-gray-600"
               title="{{ $document->repository?->full_name ?? ($document->project?->name . ' (project-wide)') }} · Source: {{ $document->sourceLabel() }}@if ($document->original_filename) · {{ $document->original_filename }}@endif">
                {{ $document->repository?->full_name ?? ($document->project?->name . ' (project-wide)') }}
                · Source: {{ $document->sourceLabel() }}
                @if ($document->original_filename)
                    · {{ $document->original_filename }}
                @endif
            </p>
            @if ($document->source_url)
                <a href="{{ $document->source_url }}" target="_blank" rel="noopener"
                   class="mt-1 inline-block max-w-full truncate text-xs text-blue-700 hover:underline">View original document</a>
            @endif
        </div>

        <span class="inline-flex shrink-0 items-center rounded-md px-3 py-1.5 text-xs font-semibold {{ $document->status->badgeClass() }}">
            {{ $document->status->label() }}
        </span>
    </div>

    @can('srs.manage')
        @if ($document->isEditable() || $document->isDeletable())
            <div class="mb-4 flex flex-wrap gap-2">
                @if ($document->isEditable())
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'srs-replace')"
                            class="rounded-md bg-white px-3 py-2 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                        Re-upload document
                    </button>
                @endif
                @if ($document->isDeletable())
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'srs-delete')"
                            class="rounded-md bg-red-50 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-100">
                        Delete revision
                    </button>
                @endif
            </div>
        @endif
    @endcan

    @can('srs.approve')
        @if ($document->status === App\Enums\SrsDocumentStatus::PendingApproval)
            <x-reauth-banner action="approving or rejecting an SRS revision" />
        @endif
    @endcan

    @if ($errors->has('checklist') || $errors->has('approval') || $errors->has('rejection_reason') || $errors->has('document') || $errors->has('reference'))
        <div class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-inset ring-red-600/20">
            {{ $errors->first('checklist')
                ?: ($errors->first('document')
                    ?: ($errors->first('reference')
                        ?: ($errors->first('approval') ?: $errors->first('rejection_reason')))) }}
        </div>
    @endif

    @if ($document->status === App\Enums\SrsDocumentStatus::Rejected && $document->rejection_reason)
        <div class="mb-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20">
            <span class="font-semibold">Rejected:</span> {{ $document->rejection_reason }}
            @if ($document->rejecter)
                <span class="text-xs text-amber-800">— {{ $document->rejecter->name }}, {{ $document->rejected_at?->diffForHumans() }}</span>
            @endif
        </div>
    @endif

    @if ($document->status === App\Enums\SrsDocumentStatus::Approved)
        <div class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-900 ring-1 ring-inset ring-green-600/20">
            This revision governs objective-aware reviews.
            @if ($document->approver)
                Approved by {{ $document->approver->name }} {{ $document->approved_at?->diffForHumans() }}.
            @endif
        </div>
    @endif

    <div class="rounded-lg bg-white shadow overflow-hidden" x-data="srsChecklistGuard(@js($document->isEditable()))">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Requirement Checklist</h3>
                <p class="mt-1 text-xs text-gray-600">
                    Mandatory requirements block merging when confirmed non-compliant. Edits are only possible before approval.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @can('srs.manage')
                    @if ($document->isEditable())
                        <form id="extract-form" method="POST" action="{{ route('admin.srs.extract', $document) }}">
                            @csrf
                            <button type="button" x-on:click="handleAction('submit-form', 'extract-form')"
                                    class="rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-200">
                                Re-extract from document
                            </button>
                        </form>
                        <form id="submit-approval-form" method="POST" action="{{ route('admin.srs.submit', $document) }}">
                            @csrf
                            <button type="button" x-on:click="handleAction('submit-form', 'submit-approval-form')"
                                    class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white hover:bg-gray-800">
                                Submit for approval
                            </button>
                        </form>
                    @endif
                @endcan

                @can('srs.approve')
                    @if ($document->status === App\Enums\SrsDocumentStatus::PendingApproval)
                        <form method="POST" action="{{ route('admin.srs.approve', $document) }}">
                            @csrf
                            <button type="submit" class="rounded-md bg-green-600 px-3 py-2 text-xs font-medium text-white hover:bg-green-700">
                                Approve & activate
                            </button>
                        </form>
                        <button type="button" x-data x-on:click="$dispatch('open-modal', 'srs-reject')"
                                class="rounded-md bg-red-50 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-100">
                            Reject
                        </button>
                    @endif
                @endcan
            </div>
        </div>

        <form method="POST" action="{{ route('admin.srs.checklist.update', $document) }}"
              x-on:submit="submitChecklist()" x-on:input="markDirty()" x-on:change="markDirty()">
            @csrf
            @method('PUT')

            <div class="overflow-x-auto min-w-full">
                <table class="w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Code</th>
                            <th scope="col" class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Requirement</th>
                            <th scope="col" class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Mandatory</th>
                            @if ($document->isEditable())
                                <th scope="col" class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Remove</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($objectives as $index => $objective)
                            <tr>
                                <td class="px-4 py-3 align-top">
                                    <input type="hidden" name="items[{{ $index }}][id]" value="{{ $objective->id }}">
                                    <input type="text" name="items[{{ $index }}][code]" value="{{ old('items.'.$index.'.code', $objective->code) }}"
                                           @disabled(! $document->isEditable())
                                           class="w-28 rounded-md border-gray-300 font-mono text-xs disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60 @error('items.'.$index.'.code') border-red-500 @enderror">
                                    <x-input-error :messages="$errors->get('items.'.$index.'.code')" class="mt-1" />
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" name="items[{{ $index }}][title]" value="{{ old('items.'.$index.'.title', $objective->title) }}"
                                           @disabled(! $document->isEditable())
                                           class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60 @error('items.'.$index.'.title') border-red-500 @enderror">
                                    <x-input-error :messages="$errors->get('items.'.$index.'.title')" class="mt-1" />
                                    @if ($objective->description || old('items.'.$index.'.description') || $errors->has('items.'.$index.'.description'))
                                        <textarea name="items[{{ $index }}][description]" rows="2"
                                                  @disabled(! $document->isEditable())
                                                  class="mt-2 w-full rounded-md border-gray-300 text-xs text-gray-600 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed disabled:opacity-60 @error('items.'.$index.'.description') border-red-500 @enderror">{{ old('items.'.$index.'.description', $objective->description) }}</textarea>
                                        <x-input-error :messages="$errors->get('items.'.$index.'.description')" class="mt-1" />
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <input type="hidden" name="items[{{ $index }}][is_mandatory]" value="0">
                                    <input type="checkbox" name="items[{{ $index }}][is_mandatory]" value="1"
                                           @checked($objective->is_mandatory) @disabled(! $document->isEditable())
                                           class="rounded border-gray-300 disabled:cursor-not-allowed disabled:opacity-60">
                                </td>
                                @if ($document->isEditable())
                                    <td class="px-4 py-3 align-top">
                                        <input type="checkbox" name="items[{{ $index }}][remove]" value="1"
                                               @disabled(! $document->isEditable())
                                               class="rounded border-gray-300 disabled:cursor-not-allowed disabled:opacity-60">
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">
                                    No requirements were extracted. Re-extract, or add them manually below.
                                </td>
                            </tr>
                        @endforelse

                        @if ($document->isEditable())
                            <tr class="bg-gray-50/60">
                                <td class="px-4 py-3 align-top">
                                    <input type="text" name="items[new][code]" value="{{ old('items.new.code') }}" placeholder="REQ-NEW"
                                           class="w-28 rounded-md border-gray-300 font-mono text-xs @error('items.new.code') border-red-500 @enderror">
                                    <x-input-error :messages="$errors->get('items.new.code')" class="mt-1" />
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" name="items[new][title]" value="{{ old('items.new.title') }}" placeholder="Add a requirement manually"
                                           class="w-full rounded-md border-gray-300 text-sm @error('items.new.title') border-red-500 @enderror">
                                    <x-input-error :messages="$errors->get('items.new.title')" class="mt-1" />
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <input type="hidden" name="items[new][is_mandatory]" value="0">
                                    <input type="checkbox" name="items[new][is_mandatory]" value="1" class="rounded border-gray-300">
                                </td>
                                <td class="px-4 py-3"></td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            @can('srs.manage')
                @if ($document->isEditable())
                    <div class="flex justify-end border-t border-gray-200 px-6 py-4">
                        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                            Save checklist
                        </button>
                    </div>
                @endif
            @endcan
        </form>

        <x-modal name="unsaved-changes-alert" maxWidth="md">
            <div class="p-6">
                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Unsaved Checklist Changes</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            You have unsaved changes in the requirement checklist. Kindly save your changes before proceeding, or your edits will be lost.
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button"
                            x-on:click="$dispatch('close-modal', 'unsaved-changes-alert')"
                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Return to Checklist
                    </button>
                    <button type="button"
                            x-on:click="proceedAnyway()"
                            class="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                        Discard & Proceed
                    </button>
                </div>
            </div>
        </x-modal>
    </div>

    @can('srs.approve')
        <x-modal name="srs-reject" maxWidth="lg">
            <form method="POST" action="{{ route('admin.srs.reject', $document) }}" class="p-6">
                @csrf
                <h3 class="text-base font-semibold text-gray-900">Reject Revision</h3>
                <p class="mt-1 text-sm text-gray-600">The reason is recorded in the audit ledger and shown to the submitter.</p>

                <textarea name="reason" rows="3" required minlength="5"
                          class="mt-4 w-full rounded-md border-gray-300 text-sm"
                          placeholder="Explain what must change before approval."></textarea>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" x-on:click="$dispatch('close-modal', 'srs-reject')"
                            class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                        Cancel
                    </button>
                    <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                        Reject Revision
                    </button>
                </div>
            </form>
        </x-modal>
    @endcan

    @can('srs.manage')
        @if ($document->isEditable())
            <x-modal name="srs-replace" :show="$errors->has('document') || $errors->has('reference')" maxWidth="2xl" focusable>
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-900">Re-upload document</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Replace the source for this draft revision. The checklist will be re-extracted automatically.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('admin.srs.replace', $document) }}"
                    enctype="multipart/form-data"
                    class="space-y-5 p-6"
                    x-data="srsReplaceForm({
                        source: @js(old('source_type', $document->source_type ?: 'upload')),
                        documentError: @js($errors->first('document')),
                    })"
                >
                    @csrf

                    <div>
                        <label for="srs-replace-title" class="block text-sm font-medium text-gray-700">Document title (optional)</label>
                        <input id="srs-replace-title" name="title" type="text" value="{{ old('title', $document->title) }}"
                               class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    </div>

                    <div>
                        <label for="srs-replace-source" class="block text-sm font-medium text-gray-700">Source</label>
                        <select id="srs-replace-source" name="source_type" x-model="source" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            @foreach ($availableSources as $source)
                                <option value="{{ $source }}">
                                    {{ ['upload' => 'Upload document', 'github' => 'GitHub file', 'google_drive' => 'Google Drive file'][$source] ?? $source }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="source === 'upload'" x-cloak>
                        <label for="srs-replace-document" class="block text-sm font-medium text-gray-700">Document file</label>
                        <input id="srs-replace-document" name="document" type="file" accept=".md,.markdown,.txt,.text,.pdf,.docx"
                               x-on:change="onDocumentChange($event)"
                               x-bind:class="documentError ? 'border-red-400' : 'border-gray-300'"
                               class="mt-1 block w-full rounded-md border p-2 text-sm">
                        <p class="mt-1 text-xs text-gray-500">Markdown, TXT, PDF, or DOCX up to 10 MB.</p>
                        <p x-show="documentError" x-cloak x-text="documentError" class="mt-1 text-sm text-red-600"></p>
                    </div>

                    <div x-show="source !== 'upload'" x-cloak class="space-y-4">
                        <div>
                            <label for="srs-replace-reference" class="block text-sm font-medium text-gray-700">
                                <span x-show="source === 'github'">Repository path or GitHub file URL</span>
                                <span x-show="source === 'google_drive'">Drive file ID or sharing link</span>
                            </label>
                            <input id="srs-replace-reference" name="reference" type="text" value="{{ old('reference') }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm @error('reference') border-red-400 @enderror"
                                   x-bind:placeholder="source === 'google_drive' ? 'https://drive.google.com/file/d/FILE_ID/view' : 'docs/SRS.md'">
                            <x-input-error :messages="$errors->get('reference')" class="mt-1" />
                        </div>

                        <div x-show="source === 'github'" x-cloak>
                            <label for="srs-replace-git-ref" class="block text-sm font-medium text-gray-700">Branch or tag (optional)</label>
                            <input id="srs-replace-git-ref" name="git_ref" type="text" value="{{ old('git_ref') }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                   placeholder="main">
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-5">
                        <button type="button" x-on:click="$dispatch('close-modal', 'srs-replace')"
                                class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                            Cancel
                        </button>
                        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                            Replace & re-extract
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif

        @if ($document->isDeletable())
            <x-modal name="srs-delete" maxWidth="md">
                <div class="p-6">
                    <h3 class="text-base font-semibold text-gray-900">Delete SRS revision</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Delete <span class="font-medium text-gray-900">{{ $document->title }} — v{{ $document->version }}</span>?
                        This removes the revision and its checklist. Approved revisions cannot be deleted.
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" x-on:click="$dispatch('close-modal', 'srs-delete')"
                                class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                            Cancel
                        </button>
                        <form method="POST" action="{{ route('admin.srs.destroy', $document) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                Delete revision
                            </button>
                        </form>
                    </div>
                </div>
            </x-modal>
        @endif
    @endcan
@endsection

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('srsReplaceForm', ({ source, documentError }) => ({
            source: source || 'upload',
            documentError: documentError || '',
            allowedExtensions: ['md', 'markdown', 'txt', 'text', 'pdf', 'docx'],

            onDocumentChange(event) {
                const file = event.target.files?.[0] ?? null;

                if (! file) {
                    this.documentError = '';

                    return;
                }

                const name = String(file.name || '');
                const extension = name.includes('.')
                    ? name.slice(name.lastIndexOf('.') + 1).toLowerCase()
                    : '';

                if (! this.allowedExtensions.includes(extension)) {
                    this.documentError = 'Unsupported file type. Upload Markdown, TXT, PDF, or DOCX.';

                    return;
                }

                this.documentError = '';
            },
        }));

        Alpine.data('srsChecklistGuard', (isEditable) => ({
            isDirty: false,
            pendingAction: null,
            pendingParam: null,

            init() {
                if (!isEditable) return;

                window.addEventListener('beforeunload', (e) => {
                    if (this.isDirty) {
                        e.preventDefault();
                        e.returnValue = 'Unsaved changes will be lost. Kindly save your changes before proceeding.';
                        return e.returnValue;
                    }
                });

                document.addEventListener('change', (e) => {
                    const target = e.target;
                    if (!target || target.type !== 'checkbox' || !target.name) return;
                    const tr = target.closest('tr');
                    if (!tr) return;

                    if (target.name.includes('[is_mandatory]') && target.checked) {
                        const removeCb = tr.querySelector('input[type="checkbox"][name*="[remove]"]');
                        if (removeCb) removeCb.checked = false;
                    } else if (target.name.includes('[remove]') && target.checked) {
                        const mandatoryCb = tr.querySelector('input[type="checkbox"][name*="[is_mandatory]"]');
                        if (mandatoryCb) mandatoryCb.checked = false;
                    }
                });

                document.addEventListener('click', (e) => {
                    if (!this.isDirty) return;
                    const link = e.target.closest('a');
                    if (link && link.href && !link.target && !link.hasAttribute('download') && link.href !== '#' && !link.href.startsWith('javascript:')) {
                        e.preventDefault();
                        this.handleAction('navigate', link.href);
                    }
                });
            },

            markDirty() {
                if (isEditable) {
                    this.isDirty = true;
                }
            },

            submitChecklist() {
                this.isDirty = false;
            },

            handleAction(actionType, param) {
                if (this.isDirty) {
                    this.pendingAction = actionType;
                    this.pendingParam = param;
                    this.$dispatch('open-modal', 'unsaved-changes-alert');
                } else {
                    this.executeAction(actionType, param);
                }
            },

            executeAction(actionType, param) {
                this.isDirty = false;
                if (actionType === 'submit-form') {
                    const form = document.getElementById(param);
                    if (form) form.submit();
                } else if (actionType === 'navigate') {
                    window.location.href = param;
                }
            },

            proceedAnyway() {
                this.$dispatch('close-modal', 'unsaved-changes-alert');
                if (this.pendingAction && this.pendingParam) {
                    this.executeAction(this.pendingAction, this.pendingParam);
                }
            }
        }));
    });
</script>
@endpush
