<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Enums\IntegrationType;
use App\Http\Controllers\Controller;
use App\Services\Admin\AuditLogService;
use App\Services\Integrations\IntegrationCredentialService;
use App\Services\Integrations\LiveIntegrationHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function __construct(
        protected IntegrationCredentialService $credentials,
        protected LiveIntegrationHealthService $health,
        protected AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        return view('admin.integrations.index', [
            'probes' => $this->health->probeAll(),
            'githubSource' => $this->credentials->github()['source'],
            'trelloSource' => $this->credentials->trello()['source'] ?? 'env',
            'driveSource' => $this->credentials->googleDrive()['source'] ?? 'env',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $type = IntegrationType::from($request->validate([
            'type' => ['required', 'in:github,trello,google_drive'],
        ])['type']);

        $credentials = match ($type) {
            IntegrationType::GitHub => $request->validate([
                'token' => ['nullable', 'string', 'max:500'],
                'app_id' => ['nullable', 'string', 'max:64'],
                'private_key' => ['nullable', 'string', 'max:10000'],
                'installation_id' => ['nullable', 'string', 'max:64'],
            ]),
            IntegrationType::Trello => $request->validate([
                'key' => ['nullable', 'string', 'max:255'],
                'token' => ['nullable', 'string', 'max:255'],
            ]),
            IntegrationType::GoogleDrive => $request->validate([
                'service_account' => ['nullable', 'string', 'max:20000'],
                'folder_id' => ['nullable', 'string', 'max:255'],
                'subject' => ['nullable', 'email', 'max:255'],
            ]),
            default => [],
        };

        $integration = $this->credentials->rotate($type, $credentials);

        $this->auditLogService->record(
            category: AuditCategory::Settings,
            eventType: AuditEventType::Updated,
            action: 'Integration credentials rotated',
            severity: AuditSeverity::Warning,
            target: $integration,
            reason: 'Credential rotation via admin UI',
            afterState: [
                'type' => $type->value,
                'fields' => array_keys(array_filter($credentials)),
            ],
            actor: $request->user(),
        );

        return redirect()->route('admin.integrations.index')
            ->with('status', ucfirst(str_replace('_', ' ', $type->value)).' credentials updated. Live services now read the encrypted store.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $type = IntegrationType::from($request->validate([
            'type' => ['required', 'in:github,trello,google_drive'],
        ])['type']);

        if (! $this->credentials->clearStored($type)) {
            return redirect()->route('admin.integrations.index')
                ->with('status', ucfirst(str_replace('_', ' ', $type->value)).' is already using environment configuration.');
        }

        $this->auditLogService->record(
            category: AuditCategory::Settings,
            eventType: AuditEventType::Updated,
            action: 'Integration credentials cleared (env fallback)',
            severity: AuditSeverity::Warning,
            target: null,
            reason: 'Stored credentials removed via admin UI',
            afterState: [
                'type' => $type->value,
                'source' => 'env',
            ],
            actor: $request->user(),
        );

        return redirect()->route('admin.integrations.index')
            ->with('status', ucfirst(str_replace('_', ' ', $type->value)).' stored credentials cleared. Live services now read from environment configuration.');
    }
}
