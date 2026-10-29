<?php

namespace App\Services\Admin;

use App\Models\Integration;
use App\Models\Repository;

class SettingService
{
    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return [
            'application' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
                'debug' => config('app.debug') ? 'Enabled' : 'Disabled',
                'timezone' => config('app.timezone'),
                'url' => config('app.url'),
            ],
            'queue' => [
                'connection' => config('queue.default'),
                'review_processing' => 'Sequential Mac worker queue',
                'retry_policy' => 'Idempotent retries with duplicate prevention',
                'offline_worker_behavior' => 'Jobs remain queued until worker reconnects',
            ],
            'security' => [
                'dashboard_access' => 'Project Manager, Super Admin, and Security Auditor',
                'remote_access' => 'Private VPN (infrastructure) + authenticator MFA via /two-factor',
                'mfa' => 'TOTP with recovery codes (SEC-008)',
                'source_code_handling' => 'Repository code stays on the Mac worker',
                'credential_storage' => 'Encrypted integrations store with env fallback; rotate at /admin/integrations',
                'external_ai' => 'Disabled for source-code review',
            ],
            'integrations' => [
                'total' => Integration::query()->count(),
                'active' => Integration::query()->where('is_active', true)->count(),
                'healthy' => Integration::query()->where('health_status', 'healthy')->count(),
                'repositories_configured' => Repository::query()->count(),
            ],
            'review_defaults' => [
                'manual_activation' => 'Required before baseline review',
                'protected_branches' => 'main, master, staging',
                'review_scope' => 'Every commit and pull request on every branch',
                'critical_alerts' => 'Email summary with redacted evidence',
                'trello_updates' => 'One consolidated message per completed review',
            ],
        ];
    }
}
