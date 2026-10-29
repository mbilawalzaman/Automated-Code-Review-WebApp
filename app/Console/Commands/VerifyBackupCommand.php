<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class VerifyBackupCommand extends Command
{
    protected $signature = 'backup:verify {file : Backup filename under storage/app/backups or absolute path}';

    protected $description = 'Recompute and compare payload SHA-256 against the sealed backup manifest (AUD-005 / AC-011)';

    public function handle(BackupService $backupService): int
    {
        $input = (string) $this->argument('file');
        $path = str_contains($input, DIRECTORY_SEPARATOR)
            ? $input
            : storage_path('app/backups/'.basename($input));

        try {
            $result = $backupService->verifyBackup($path);
        } catch (\Throwable $e) {
            $this->error('Verification failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['valid']) {
            $this->info($result['message']);
            $this->line('SHA-256: '.$result['computed_hash']);

            return self::SUCCESS;
        }

        $this->error($result['message']);
        $this->line('Expected: '.$result['expected_hash']);
        $this->line('Computed: '.$result['computed_hash']);

        return self::FAILURE;
    }
}
