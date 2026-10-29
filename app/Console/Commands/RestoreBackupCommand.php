<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Exception;

class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore {file : Path or filename of encrypted backup archive} {--force : Force execution without interactive confirmation}';
    protected $description = 'Restore system database and storage from an encrypted backup archive (CLI safety restricted)';

    public function handle(BackupService $backupService): int
    {
        $filePath = $this->argument('file');

        // Resolve absolute or storage relative path
        if (!file_exists($filePath)) {
            $storagePath = storage_path("app/backups/{$filePath}");
            if (file_exists($storagePath)) {
                $filePath = $storagePath;
            }
        }

        if (!file_exists($filePath)) {
            $this->error("❌ Backup archive not found: {$filePath}");
            return Command::FAILURE;
        }

        $this->warn("⚠️  WARNING: Restoring a backup is a destructive operation. All current database records and reports will be overwritten.");

        if (!$this->option('force') && !$this->confirm("Are you sure you want to restore from archive: " . basename($filePath) . "?")) {
            $this->info("Restoration cancelled.");
            return Command::SUCCESS;
        }

        $this->info("Decrypting and restoring system backup from " . basename($filePath) . "...");

        try {
            $result = $backupService->restoreBackup($filePath);

            $this->info("✅ Backup restored successfully!");
            $manifest = $result['manifest'] ?? [];

            $this->table(
                ['Attribute', 'Value'],
                [
                    ['Original Backup Date', $manifest['created_at'] ?? 'Unknown'],
                    ['Database Driver', $manifest['database_driver'] ?? 'Unknown'],
                    ['Laravel Version', $manifest['laravel_version'] ?? 'Unknown'],
                    ['Status', 'Restored & Verified'],
                ]
            );

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("❌ Backup restoration failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
