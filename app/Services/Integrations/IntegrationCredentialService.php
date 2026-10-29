<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationType;
use App\Models\Integration;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves live integration credentials from encrypted Integration rows with env/config fallback (SEC-007).
 */
class IntegrationCredentialService
{
    /** @var array<string, array<string, mixed>> */
    protected array $credentialCache = [];

    /**
     * @return array{token: ?string, app_id: ?string, private_key: ?string, installation_id: ?string, source: string}
     */
    public function github(): array
    {
        $stored = $this->storedCredentials(IntegrationType::GitHub);

        return [
            'token' => $stored['token'] ?? config('github.token'),
            'app_id' => $stored['app_id'] ?? config('github.app_id'),
            'private_key' => $stored['private_key'] ?? config('github.private_key'),
            'installation_id' => $stored['installation_id'] ?? config('github.installation_id'),
            'source' => $stored === [] ? 'env' : 'database',
        ];
    }

    /**
     * @return array{key: ?string, token: ?string, source: string}|null
     */
    public function trello(): ?array
    {
        $stored = $this->storedCredentials(IntegrationType::Trello);
        $key = $stored['key'] ?? config('services.trello.key');
        $token = $stored['token'] ?? config('services.trello.token');

        if (empty($key) || empty($token)) {
            return null;
        }

        return [
            'key' => $key,
            'token' => $token,
            'source' => $stored === [] ? 'env' : 'database',
        ];
    }

    /**
     * @return array{service_account: ?string, folder_id: ?string, subject: ?string, source: string}|null
     */
    public function googleDrive(): ?array
    {
        $stored = $this->storedCredentials(IntegrationType::GoogleDrive);
        $serviceAccount = $stored['service_account'] ?? config('services.google_drive.service_account');
        $folderId = $stored['folder_id'] ?? config('services.google_drive.folder_id');

        if (empty($serviceAccount) || empty($folderId)) {
            return null;
        }

        return [
            'service_account' => $serviceAccount,
            'folder_id' => $folderId,
            'subject' => $stored['subject'] ?? config('services.google_drive.subject'),
            'source' => $stored === [] ? 'env' : 'database',
        ];
    }

    /**
     * Persist rotated credentials for a platform-level integration.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $config
     */
    public function rotate(IntegrationType $type, array $credentials, array $config = []): Integration
    {
        $integration = Integration::query()
            ->where('type', $type->value)
            ->whereNull('project_id')
            ->whereNull('repository_id')
            ->first();

        if (! $integration) {
            $integration = new Integration([
                'type' => $type,
                'name' => $type->value.' platform credentials',
                'is_active' => true,
            ]);
        }

        $existing = is_array($integration->credentials) ? $integration->credentials : [];
        $merged = array_filter(array_merge($existing, $credentials), fn ($v) => $v !== null && $v !== '');

        $integration->fill([
            'credentials' => $merged,
            'config' => array_merge(is_array($integration->config) ? $integration->config : [], $config),
            'is_active' => true,
            'health_status' => 'unknown',
            'last_synced_at' => null,
        ])->save();

        $this->forgetTokenCaches();
        unset($this->credentialCache[$type->value]);

        return $integration->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function storedCredentials(IntegrationType $type): array
    {
        $cacheKey = $type->value;
        if (array_key_exists($cacheKey, $this->credentialCache)) {
            return $this->credentialCache[$cacheKey];
        }

        $integration = Integration::query()
            ->where('type', $type->value)
            ->where('is_active', true)
            ->whereNull('project_id')
            ->whereNull('repository_id')
            ->latest('updated_at')
            ->first();

        if (! $integration || ! is_array($integration->credentials)) {
            return $this->credentialCache[$cacheKey] = [];
        }

        return $this->credentialCache[$cacheKey] = array_filter(
            $integration->credentials,
            fn ($v) => $v !== null && $v !== ''
        );
    }

    /**
     * Deactivate platform-stored credentials so live services fall back to env/config.
     */
    public function clearStored(IntegrationType $type): bool
    {
        $integration = Integration::query()
            ->where('type', $type->value)
            ->whereNull('project_id')
            ->whereNull('repository_id')
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            return false;
        }

        $integration->update([
            'is_active' => false,
            'credentials' => [],
            'health_status' => 'unknown',
            'last_synced_at' => null,
        ]);

        unset($this->credentialCache[$type->value]);
        $this->forgetTokenCaches();

        return true;
    }

    public function forgetTokenCaches(): void
    {
        // Installation tokens are keyed by installation id; flush known pattern via Cache::flush is too broad.
        // Callers that cache by installation id will miss after rotation when installation_id changes.
        Cache::forget('google_drive_access_token');
    }
}
