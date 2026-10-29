<?php

namespace App\Enums;

enum AuditEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PermissionGranted = 'permission_granted';
    case PermissionRevoked = 'permission_revoked';
    case Override = 'override';
    case JobClaimed = 'job_claimed';
    case ResultSubmitted = 'result_submitted';
    case Unauthorized = 'unauthorized';
    case ChecklistExtracted = 'checklist_extracted';
    case SubmittedForApproval = 'submitted_for_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Login => 'Login Succeeded',
            self::LoginFailed => 'Login Failed',
            self::Logout => 'Logged Out',
            self::PermissionGranted => 'Permission Granted',
            self::PermissionRevoked => 'Permission Revoked',
            self::Override => 'Manual Override',
            self::JobClaimed => 'Job Claimed',
            self::ResultSubmitted => 'Result Submitted',
            self::Unauthorized => 'Unauthorized Access',
            self::ChecklistExtracted => 'Checklist Extracted',
            self::SubmittedForApproval => 'Submitted For Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Superseded => 'Superseded',
        };
    }
}
