<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case ProjectManager = 'project_manager';
    case SecurityAuditor = 'security_auditor';
    case Developer = 'developer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::ProjectManager => 'Project Manager',
            self::SecurityAuditor => 'Security Auditor',
            self::Developer => 'Developer',
        };
    }
}
