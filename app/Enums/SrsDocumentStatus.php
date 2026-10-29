<?php

namespace App\Enums;

enum SrsDocumentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved (Active)',
            self::Rejected => 'Rejected',
            self::Superseded => 'Superseded',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-100 text-gray-700',
            self::PendingApproval => 'bg-amber-100 text-amber-800',
            self::Approved => 'bg-green-100 text-green-800',
            self::Rejected => 'bg-red-100 text-red-800',
            self::Superseded => 'bg-slate-100 text-slate-600',
        };
    }

    /**
     * Only an approved revision may influence reviews (SRS-002).
     */
    public function affectsReviews(): bool
    {
        return $this === self::Approved;
    }
}
