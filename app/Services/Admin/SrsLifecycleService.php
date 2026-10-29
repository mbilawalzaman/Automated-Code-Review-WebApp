<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\SrsDocumentStatus;
use App\Models\Objective;
use App\Models\Repository;
use App\Models\SrsDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SrsLifecycleService
{
    public function __construct(
        protected SrsSourceAdapterService $sourceAdapter,
        protected SrsChecklistExtractionService $extractor,
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * Ingest a document as a brand-new revision and extract its checklist.
     * Existing approved revisions keep governing reviews until this one is approved
     * (SRS-002), so intake is always additive.
     */
    public function createRevision(
        string $sourceType,
        ?Repository $repository,
        int $projectId,
        string $title,
        ?UploadedFile $file = null,
        ?string $reference = null,
        ?string $gitRef = null,
    ): SrsDocument {
        $intake = $this->sourceAdapter->intake(
            sourceType: $sourceType,
            repository: $repository,
            file: $file,
            reference: $reference,
            gitRef: $gitRef,
        );

        $document = DB::transaction(function () use ($intake, $repository, $projectId, $title) {
            $document = SrsDocument::query()->create([
                'project_id' => $projectId,
                'repository_id' => $repository?->id,
                'title' => $title,
                'version' => $this->nextVersion($projectId, $repository?->id),
                'source_type' => $intake['source_type'],
                'source_url' => $intake['source_url'],
                'file_path' => $intake['file_path'],
                'original_filename' => $intake['original_filename'],
                'content_hash' => $intake['content_hash'],
                'extracted_text' => $intake['text'],
                'status' => SrsDocumentStatus::Draft,
            ]);

            $this->syncExtractedChecklist($document);

            return $document;
        });

        $this->auditLogService->record(
            category: AuditCategory::Srs,
            eventType: AuditEventType::Created,
            action: "SRS revision v{$document->version} '{$document->title}' created from {$document->sourceLabel()}",
            target: $document,
            metadata: [
                'source_type' => $document->source_type,
                'source_url' => $document->source_url,
                'content_hash' => $document->content_hash,
                'requirements_extracted' => $document->objectives()->count(),
            ],
            reason: 'SRS document intake (SRS-001)'
        );

        return $document->refresh();
    }

    /**
     * Re-run extraction against the stored text. Approved revisions are immutable.
     */
    public function reExtractChecklist(SrsDocument $document): SrsDocument
    {
        $this->assertEditable($document);

        DB::transaction(function () use ($document) {
            $document->objectives()->delete();
            $this->syncExtractedChecklist($document);
        });

        $this->auditLogService->record(
            category: AuditCategory::Srs,
            eventType: AuditEventType::ChecklistExtracted,
            action: "Checklist re-extracted for SRS revision v{$document->version}",
            target: $document,
            metadata: ['requirements_extracted' => $document->objectives()->count()],
            reason: 'Checklist extraction (SRS-003)'
        );

        return $document->refresh();
    }

    /**
     * Replace the source document on a draft/rejected revision and re-extract.
     */
    public function replaceDocument(
        SrsDocument $document,
        string $sourceType,
        ?UploadedFile $file = null,
        ?string $reference = null,
        ?string $gitRef = null,
        ?string $title = null,
    ): SrsDocument {
        $this->assertEditable($document);

        $intake = $this->sourceAdapter->intake(
            sourceType: $sourceType,
            repository: $document->repository,
            file: $file,
            reference: $reference,
            gitRef: $gitRef,
        );

        $previousPath = $document->file_path;

        DB::transaction(function () use ($document, $intake, $title) {
            $document->update([
                'title' => ($title !== null && trim($title) !== '') ? trim($title) : $document->title,
                'source_type' => $intake['source_type'],
                'source_url' => $intake['source_url'],
                'file_path' => $intake['file_path'],
                'original_filename' => $intake['original_filename'],
                'content_hash' => $intake['content_hash'],
                'extracted_text' => $intake['text'],
            ]);

            $document->objectives()->delete();
            $this->syncExtractedChecklist($document->refresh());
        });

        if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $intake['file_path']) {
            Storage::disk('local')->delete($previousPath);
        }

        $document->refresh();

        $this->auditLogService->record(
            category: AuditCategory::Srs,
            eventType: AuditEventType::Updated,
            action: "SRS revision v{$document->version} document replaced from {$document->sourceLabel()}",
            target: $document,
            metadata: [
                'source_type' => $document->source_type,
                'original_filename' => $document->original_filename,
                'content_hash' => $document->content_hash,
                'requirements_extracted' => $document->objectives()->count(),
            ],
            reason: 'SRS document replaced'
        );

        return $document;
    }

    /**
     * Permanently remove a non-governing revision (wrong upload / abandoned draft).
     */
    public function deleteRevision(SrsDocument $document): void
    {
        if (! $document->isDeletable()) {
            throw new RuntimeException('Approved or superseded revisions cannot be deleted. Create a new revision instead.');
        }

        $path = $document->file_path;
        $version = $document->version;
        $title = $document->title;
        $documentId = $document->id;

        DB::transaction(function () use ($document) {
            $document->objectives()->delete();
            $document->delete();
        });

        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }

        $this->auditLogService->record(
            category: AuditCategory::Srs,
            eventType: AuditEventType::Deleted,
            action: "SRS revision v{$version} '{$title}' deleted",
            target: null,
            metadata: [
                'srs_document_id' => $documentId,
                'title' => $title,
                'version' => $version,
            ],
            reason: 'SRS document deleted'
        );
    }

    /**
     * Apply Project Manager edits to the checklist before approval.
     *
     * @param  array<int, array{id?: int|string|null, code?: string|null, title?: string|null, description?: string|null, is_mandatory?: mixed, remove?: mixed}>  $items
     */
    public function updateChecklist(SrsDocument $document, array $items): SrsDocument
    {
        $this->assertEditable($document);
        $this->assertUniqueRequirementCodes($items);

        DB::transaction(function () use ($document, $items) {
            $existing = $document->objectives()->get()->keyBy('id');
            $order = 0;
            $toDelete = [];
            $toInsert = [];
            $now = now();

            // Track existing codes to calculate next auto-generated REQ-xxx number
            $existingCodes = [];
            $maxNumber = 0;
            foreach ($items as $item) {
                if (filter_var($item['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $c = strtoupper(trim((string) ($item['code'] ?? '')));
                if ($c !== '' && $c !== 'REQ-NEW') {
                    $existingCodes[$c] = true;
                    if (preg_match('/^REQ-(\d+)$/i', $c, $m)) {
                        $maxNumber = max($maxNumber, (int) $m[1]);
                    }
                }
            }

            foreach ($items as $item) {
                $id = isset($item['id']) ? (int) $item['id'] : 0;
                $objective = $id > 0 ? $existing->get($id) : null;
                $remove = filter_var($item['remove'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if ($objective && $remove) {
                    $toDelete[] = $objective->id;

                    continue;
                }

                $title = trim((string) ($item['title'] ?? ''));

                if ($title === '') {
                    continue;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                if ($code === '' || $code === 'REQ-NEW') {
                    do {
                        $maxNumber++;
                        $code = 'REQ-' . str_pad((string) $maxNumber, 3, '0', STR_PAD_LEFT);
                    } while (isset($existingCodes[$code]));
                    $existingCodes[$code] = true;
                }

                $order++;
                $attributes = [
                    'code' => $code,
                    'title' => mb_substr($title, 0, 255),
                    'description' => trim((string) ($item['description'] ?? '')) ?: null,
                    'is_mandatory' => filter_var($item['is_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'sort_order' => $order,
                ];

                if ($objective) {
                    $objective->update($attributes);

                    continue;
                }

                $toInsert[] = $attributes + [
                    'project_id' => $document->project_id,
                    'srs_document_id' => $document->id,
                    'is_approved' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($toDelete !== []) {
                Objective::query()->whereIn('id', $toDelete)->delete();
            }

            foreach (array_chunk($toInsert, 100) as $chunk) {
                Objective::query()->insert($chunk);
            }

            $document->update(['checklist' => $this->checklistSnapshot($document->refresh())]);
        });

        $this->auditLogService->record(
            category: AuditCategory::Srs,
            eventType: AuditEventType::Updated,
            action: "Checklist edited for SRS revision v{$document->version}",
            target: $document,
            metadata: ['requirements' => $document->objectives()->count()],
            reason: 'Checklist curation before approval (SRS-003)'
        );

        return $document->refresh();
    }

    public function submitForApproval(SrsDocument $document): SrsDocument
    {
        $this->assertEditable($document);

        if ($document->objectives()->count() === 0) {
            throw new RuntimeException('Add at least one requirement before submitting for approval.');
        }

        $document->update([
            'status' => SrsDocumentStatus::PendingApproval,
            'submitted_at' => now(),
            'rejection_reason' => null,
            'rejected_by' => null,
            'rejected_at' => null,
        ]);

        $this->auditLogService->record(
            category: AuditCategory::Srs,
            eventType: AuditEventType::SubmittedForApproval,
            action: "SRS revision v{$document->version} submitted for Project Manager approval",
            target: $document,
            reason: 'Approval required before affecting reviews (SRS-002)'
        );

        return $document->refresh();
    }

    /**
     * Approve the revision and its checklist, superseding the previous active one.
     */
    public function approve(SrsDocument $document, User $approver): SrsDocument
    {
        if ($document->status === SrsDocumentStatus::Approved) {
            return $document;
        }

        if ($document->status !== SrsDocumentStatus::PendingApproval) {
            throw new RuntimeException('Only revisions pending approval can be approved.');
        }

        if ($document->objectives()->count() === 0) {
            throw new RuntimeException('This revision has no requirements to approve.');
        }

        $superseded = DB::transaction(function () use ($document, $approver) {
            $superseded = SrsDocument::query()
                ->activeApproved()
                ->where('project_id', $document->project_id)
                ->where('repository_id', $document->repository_id)
                ->whereKeyNot($document->getKey())
                ->get();

            foreach ($superseded as $previous) {
                $previous->forceFill([
                    'status' => SrsDocumentStatus::Superseded,
                    'superseded_at' => now(),
                ]);
            }

            if ($superseded->isNotEmpty()) {
                $supersededIds = $superseded->pluck('id')->all();
                SrsDocument::query()->whereIn('id', $supersededIds)->update([
                    'status' => SrsDocumentStatus::Superseded->value,
                    'superseded_at' => now(),
                ]);
                Objective::query()->whereIn('srs_document_id', $supersededIds)->update([
                    'is_approved' => false,
                ]);
            }

            $document->update([
                'status' => SrsDocumentStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'superseded_at' => null,
                'checklist' => $this->checklistSnapshot($document),
            ]);

            $document->objectives()->update([
                'is_approved' => true,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $superseded;
        });

        $this->auditLogService->record(
            category: AuditCategory::Srs,
            eventType: AuditEventType::Approved,
            action: "SRS revision v{$document->version} approved and activated for reviews",
            severity: AuditSeverity::Warning,
            target: $document,
            afterState: [
                'status' => SrsDocumentStatus::Approved->value,
                'approved_requirements' => $document->objectives()->count(),
            ],
            metadata: ['superseded_ids' => $superseded->pluck('id')->all()],
            reason: 'Project Manager approval (SRS-002 / SRS-003)',
            actor: $approver
        );

        foreach ($superseded as $previous) {
            $this->auditLogService->record(
                category: AuditCategory::Srs,
                eventType: AuditEventType::Superseded,
                action: "SRS revision v{$previous->version} superseded by v{$document->version}",
                target: $previous,
                reason: 'Newer revision approved',
                actor: $approver
            );
        }

        return $document->refresh();
    }

    public function reject(SrsDocument $document, User $approver, string $reason): SrsDocument
    {
        if ($document->status !== SrsDocumentStatus::PendingApproval) {
            throw new RuntimeException('Only revisions pending approval can be rejected.');
        }

        $document->update([
            'status' => SrsDocumentStatus::Rejected,
            'rejected_by' => $approver->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->auditLogService->record(
            category: AuditCategory::Srs,
            eventType: AuditEventType::Rejected,
            action: "SRS revision v{$document->version} rejected",
            severity: AuditSeverity::Warning,
            target: $document,
            reason: $reason,
            actor: $approver
        );

        return $document->refresh();
    }

    /**
     * The revision currently governing a repository's reviews, preferring a
     * repository-specific document over a project-wide one (SRS-004 fallback
     * happens when this returns null).
     */
    public function activeFor(Repository $repository): ?SrsDocument
    {
        return SrsDocument::query()
            ->activeApproved()
            ->forRepositoryOrProject($repository)
            ->orderByRaw('CASE WHEN repository_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Approved objectives for the worker payload; empty means technical/security
     * review only (SRS-004).
     *
     * @return list<array<string, mixed>>
     */
    public function objectivePayloadFor(Repository $repository): array
    {
        $document = $this->activeFor($repository);

        if (! $document) {
            return [];
        }

        return $document->objectives()
            ->approved()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Objective $objective) => $objective->toWorkerPayload())
            ->all();
    }

    protected function syncExtractedChecklist(SrsDocument $document): void
    {
        $requirements = $this->extractor->extract((string) $document->extracted_text);
        $now = now();
        $rows = [];

        foreach ($requirements as $requirement) {
            $rows[] = [
                'project_id' => $document->project_id,
                'srs_document_id' => $document->id,
                'code' => $requirement['code'],
                'title' => $requirement['title'],
                'description' => $requirement['description'],
                'is_mandatory' => (bool) $requirement['is_mandatory'],
                'sort_order' => $requirement['sort_order'],
                'is_approved' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            Objective::query()->insert($chunk);
        }

        $document->update([
            'checklist' => $this->checklistSnapshot($document->refresh()),
            'extracted_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function checklistSnapshot(SrsDocument $document): array
    {
        return $document->objectives()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Objective $objective) => [
                'code' => $objective->code,
                'title' => $objective->title,
                'is_mandatory' => (bool) $objective->is_mandatory,
            ])
            ->all();
    }

    protected function nextVersion(int $projectId, ?int $repositoryId): int
    {
        $query = SrsDocument::query()->where('project_id', $projectId);

        $repositoryId === null
            ? $query->whereNull('repository_id')
            : $query->where('repository_id', $repositoryId);

        return (int) $query->max('version') + 1;
    }

    protected function assertEditable(SrsDocument $document): void
    {
        if (! $document->isEditable()) {
            throw new RuntimeException('Approved, superseded, or pending revisions cannot be edited. Create a new revision instead.');
        }
    }

    /**
     * Reject duplicate non-empty requirement codes within one checklist save.
     *
     * @param  array<int, array{id?: int|string|null, code?: string|null, title?: string|null, remove?: mixed}>  $items
     */
    protected function assertUniqueRequirementCodes(array $items): void
    {
        $seen = [];

        foreach ($items as $item) {
            if (filter_var($item['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $code = strtoupper(trim((string) ($item['code'] ?? '')));

            // Empty new rows and blank codes are ignored; only filled codes must be unique.
            if ($title === '' || $code === '') {
                continue;
            }

            if (isset($seen[$code])) {
                throw new RuntimeException(
                    "Duplicate requirement code \"{$code}\" is not allowed in the same checklist."
                );
            }

            $seen[$code] = true;
        }
    }

    /**
     * @return Collection<int, SrsDocument>
     */
    public function revisionsFor(Repository $repository): Collection
    {
        return SrsDocument::query()
            ->forRepositoryOrProject($repository)
            ->with(['approver', 'rejecter'])
            ->orderByDesc('version')
            ->get();
    }
}
