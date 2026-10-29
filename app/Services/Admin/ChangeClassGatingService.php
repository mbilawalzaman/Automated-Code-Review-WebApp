<?php

namespace App\Services\Admin;

use App\Enums\ChangeClass;
use App\Models\Review;
use App\Services\BackupService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ChangeClassGatingService
{
    public function __construct(
        protected BackupService $backupService,
    ) {}

    /**
     * Enforce CHG-002 / CHG-003 gates before a PM override is accepted.
     *
     * @param  array{documentation_url?: ?string, documentation_notes?: ?string}  $context
     */
    public function assertOverrideAllowed(Review $review, array $context = []): void
    {
        $classes = $this->resolveChangeClasses($review);

        foreach ($classes as $changeClass) {
            if ($changeClass->requiresVerifiedBackup()) {
                $this->assertVerifiedRecentBackup();
            }

            if ($changeClass->requiresDocumentation()) {
                $this->assertDocumentationProvided($context);
            }
        }
    }

    /**
     * @return list<ChangeClass>
     */
    public function resolveChangeClasses(Review $review): array
    {
        $raw = $review->change_classes ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }

        $resolved = [];
        foreach ($raw as $value) {
            $enum = ChangeClass::tryFrom((string) $value);
            if ($enum !== null) {
                $resolved[$enum->value] = $enum;
            }
        }

        return array_values($resolved);
    }

    protected function assertVerifiedRecentBackup(): void
    {
        $maxAgeHours = (int) config('worker.destructive_change_backup_max_age_hours', 24);
        $cutoff = Carbon::now()->subHours(max(1, $maxAgeHours));

        $backups = $this->backupService->listBackups();
        if ($backups === []) {
            throw new InvalidArgumentException(
                'Destructive migration override requires a verified backup within the last '
                .$maxAgeHours.' hour(s). No backups were found (CHG-002).'
            );
        }

        foreach ($backups as $backup) {
            $createdAt = Carbon::parse($backup['created_at'] ?? '1970-01-01');
            if ($createdAt->lt($cutoff)) {
                continue;
            }

            $path = $backup['path'] ?? null;
            if (! is_string($path) || $path === '') {
                continue;
            }

            try {
                $result = $this->backupService->verifyBackup($path);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException(
                    'Destructive migration override blocked: backup verification failed — '.$e->getMessage()
                );
            }

            if (($result['valid'] ?? false) === true) {
                return;
            }
        }

        throw new InvalidArgumentException(
            'Destructive migration override requires a verified backup created within the last '
            .$maxAgeHours.' hour(s) (CHG-002). Create or restore eligibility by running a fresh backup:verify.'
        );
    }

    /**
     * @param  array{documentation_url?: ?string, documentation_notes?: ?string}  $context
     */
    protected function assertDocumentationProvided(array $context): void
    {
        $url = trim((string) ($context['documentation_url'] ?? ''));
        $notes = trim((string) ($context['documentation_notes'] ?? ''));

        if ($url === '' && $notes === '') {
            throw new InvalidArgumentException(
                'API contract changes require documentation (URL or notes) before override (CHG-003).'
            );
        }

        if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Documentation URL must be a valid URL.');
        }
    }
}
