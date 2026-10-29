<?php

namespace App\Enums;

enum AuditCategory: string
{
    case Auth = 'auth';
    case User = 'user';
    case Rbac = 'rbac';
    case Repository = 'repository';
    case Project = 'project';
    case Review = 'review';
    case Finding = 'finding';
    case Srs = 'srs';
    case Security = 'security';
    case Worker = 'worker';
    case Settings = 'settings';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Auth => 'Authentication',
            self::User => 'User Management',
            self::Rbac => 'Roles & Permissions',
            self::Repository => 'Repository Management',
            self::Project => 'Project Management',
            self::Review => 'Code Reviews',
            self::Finding => 'Security Findings',
            self::Srs => 'Objectives & SRS',
            self::Security => 'Security Events',
            self::Worker => 'Worker Engine',
            self::Settings => 'System Settings',
            self::Api => 'API Access',
        };
    }
}
