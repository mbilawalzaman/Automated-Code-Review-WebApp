<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Define All System Permissions
        $permissions = [
            // User Management
            ['name' => 'users.view', 'display_name' => 'View Users', 'group' => 'users', 'description' => 'View list of system users'],
            ['name' => 'users.manage', 'display_name' => 'Manage Users', 'group' => 'users', 'description' => 'Create, edit, and delete system users'],

            // Role Management
            ['name' => 'roles.view', 'display_name' => 'View Roles', 'group' => 'roles', 'description' => 'View roles and permission assignments'],
            ['name' => 'roles.manage', 'display_name' => 'Manage Roles', 'group' => 'roles', 'description' => 'Create, edit, and delete custom roles and assign permissions'],

            // Project Management
            ['name' => 'projects.view', 'display_name' => 'View Projects', 'group' => 'projects', 'description' => 'View system project profiles'],
            ['name' => 'projects.manage', 'display_name' => 'Manage Projects', 'group' => 'projects', 'description' => 'Create, update, and configure projects'],

            // Repository Management
            ['name' => 'repositories.view', 'display_name' => 'View Repositories', 'group' => 'repositories', 'description' => 'View GitHub repositories and branches'],
            ['name' => 'repositories.manage', 'display_name' => 'Manage Repositories', 'group' => 'repositories', 'description' => 'Add, update, and manage repository configurations'],

            // Objectives & SRS Management
            ['name' => 'srs.view', 'display_name' => 'View Objectives & SRS', 'group' => 'srs', 'description' => 'View SRS revisions, sources, and requirement checklists'],
            ['name' => 'srs.manage', 'display_name' => 'Manage Objectives & SRS', 'group' => 'srs', 'description' => 'Upload or link SRS documents, extract checklists, and edit requirements'],
            ['name' => 'srs.approve', 'display_name' => 'Approve Objectives & SRS', 'group' => 'srs', 'description' => 'Approve or reject SRS revisions and checklists that govern reviews'],

            // Review History
            ['name' => 'reviews.view', 'display_name' => 'View Reviews', 'group' => 'reviews', 'description' => 'View automated code review records and pipeline jobs'],
            ['name' => 'reviews.override', 'display_name' => 'Override Reviews', 'group' => 'reviews', 'description' => 'Override failed reviews with a mandatory reason'],

            // Review Findings
            ['name' => 'findings.view', 'display_name' => 'View Findings', 'group' => 'findings', 'description' => 'View security, bug, and quality findings'],
            ['name' => 'findings.manage', 'display_name' => 'Manage Findings', 'group' => 'findings', 'description' => 'Mark findings as false positive or accepted with a reason'],

            // Reports
            ['name' => 'reports.view', 'display_name' => 'View Reports', 'group' => 'reports', 'description' => 'View and export code review summary reports'],

            // Audit Logs
            ['name' => 'audit.view', 'display_name' => 'View Audit Logs', 'group' => 'audit', 'description' => 'View system audit logs and action history'],
            ['name' => 'audit.export', 'display_name' => 'Export Audit Logs', 'group' => 'audit', 'description' => 'Export audit logs as CSV or JSON reports'],
            ['name' => 'audit.manage', 'display_name' => 'Manage Audit Logs', 'group' => 'audit', 'description' => 'Configure audit log retention and storage settings'],

            // System Settings & Health
            ['name' => 'settings.manage', 'display_name' => 'Manage Settings', 'group' => 'settings', 'description' => 'Manage system configuration and API settings'],
            ['name' => 'system.health.view', 'display_name' => 'View System Health', 'group' => 'settings', 'description' => 'View real-time operational health, worker status, and system metrics'],

            // Integrations, notifications, jobs (Phase 22)
            ['name' => 'integrations.manage', 'display_name' => 'Manage Integrations', 'group' => 'integrations', 'description' => 'Rotate encrypted integration credentials and view live connectivity'],
            ['name' => 'notifications.view', 'display_name' => 'View Notifications', 'group' => 'notifications', 'description' => 'View system notification inbox'],
            ['name' => 'notifications.manage', 'display_name' => 'Manage Notifications', 'group' => 'notifications', 'description' => 'Mark notifications read and delete inbox items'],
            ['name' => 'jobs.cancel', 'display_name' => 'Cancel Review Jobs', 'group' => 'reviews', 'description' => 'Cancel pending or processing review jobs'],
        ];

        $permissionModels = [];
        foreach ($permissions as $perm) {
            $permissionModels[$perm['name']] = Permission::query()->firstOrCreate(
                ['name' => $perm['name']],
                $perm
            );
        }

        // 2. Define Core System Protected Roles (is_system = true)
        $roles = [
            'super_admin' => [
                'display_name' => 'Super Admin',
                'description' => 'Full administrative access to all system features, security logs, and user configuration.',
                'is_system' => true,
                'permissions' => array_keys($permissionModels), // All permissions
            ],
            'project_manager' => [
                'display_name' => 'Project Manager',
                'description' => 'Manage projects, repositories, view code reviews, findings, and summary reports.',
                'is_system' => true,
                'permissions' => [
                    'users.view', 'projects.view', 'projects.manage', 'repositories.view',
                    'repositories.manage', 'reviews.view', 'reviews.override',
                    'findings.view', 'findings.manage', 'reports.view', 'system.health.view',
                    'srs.view', 'srs.manage', 'srs.approve',
                    'integrations.manage', 'notifications.view', 'notifications.manage', 'jobs.cancel',
                ],
            ],
            'security_auditor' => [
                'display_name' => 'Security Auditor',
                'description' => 'Audit review records, security findings, export reports, and inspect system audit logs.',
                'is_system' => true,
                'permissions' => [
                    'reviews.view', 'findings.view', 'reports.view', 'audit.view', 'audit.export', 'system.health.view',
                    'srs.view', 'notifications.view',
                ],
            ],
            'developer' => [
                'display_name' => 'Developer',
                'description' => 'No dashboard access by default. Views GitHub and Trello results outside the admin panel.',
                'is_system' => true,
                'permissions' => [
                    // Intentionally empty for dashboard routes (UI-001). Developers use GitHub/Trello.
                ],
            ],
        ];

        foreach ($roles as $roleName => $roleData) {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName],
                [
                    'display_name' => $roleData['display_name'],
                    'description' => $roleData['description'],
                    'is_system' => $roleData['is_system'],
                ]
            );

            $permIds = array_map(fn($pName) => $permissionModels[$pName]->id, $roleData['permissions']);
            $role->permissions()->sync($permIds);
        }

        // 3. Data Migration: Assign user_roles based on existing users.role string column
        $roleMap = Role::query()->pluck('id', 'name')->toArray();

        User::query()->each(function (User $user) use ($roleMap) {
            $roleValue = is_string($user->role) ? $user->role : ($user->role?->value ?? 'developer');
            if (isset($roleMap[$roleValue])) {
                $user->roles()->syncWithoutDetaching([$roleMap[$roleValue]]);
            }
        });
    }
}
