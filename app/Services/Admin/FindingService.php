<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class FindingService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Finding>
     */
    public function list(int $perPage = 10): LengthAwarePaginator
    {
        return Finding::query()
            ->with(['review.repository.project', 'objective'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function markFalsePositive(Finding $finding, string $reason, User $actor): Finding
    {
        if ($finding->status !== FindingStatus::Open) {
            throw new InvalidArgumentException('Only open findings can be marked as false positives.');
        }

        $before = [
            'status' => $finding->status->value,
            'is_blocking' => $finding->is_blocking,
            'false_positive_reason' => $finding->false_positive_reason,
        ];

        $finding->update([
            'status' => FindingStatus::FalsePositive,
            'false_positive_reason' => $reason,
            'is_blocking' => false,
        ]);

        $this->auditLogService->record(
            category: AuditCategory::Finding,
            eventType: AuditEventType::Updated,
            action: "Finding #{$finding->id} marked as false positive",
            severity: AuditSeverity::Warning,
            target: $finding,
            beforeState: $before,
            afterState: [
                'status' => $finding->status->value,
                'is_blocking' => $finding->is_blocking,
                'false_positive_reason' => $finding->false_positive_reason,
            ],
            reason: $reason,
            actor: $actor,
        );

        return $finding->refresh();
    }

    public function accept(Finding $finding, string $reason, User $actor): Finding
    {
        if ($finding->status !== FindingStatus::Open) {
            throw new InvalidArgumentException('Only open findings can be accepted.');
        }

        $before = [
            'status' => $finding->status->value,
            'is_blocking' => $finding->is_blocking,
        ];

        $finding->update([
            'status' => FindingStatus::Accepted,
            'is_blocking' => false,
        ]);

        $this->auditLogService->record(
            category: AuditCategory::Finding,
            eventType: AuditEventType::Updated,
            action: "Finding #{$finding->id} accepted as warning",
            severity: AuditSeverity::Warning,
            target: $finding,
            beforeState: $before,
            afterState: [
                'status' => $finding->status->value,
                'is_blocking' => $finding->is_blocking,
            ],
            reason: $reason,
            actor: $actor,
        );

        return $finding->refresh();
    }
}
