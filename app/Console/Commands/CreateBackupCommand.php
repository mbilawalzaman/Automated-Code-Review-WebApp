<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Exception;

class CreateBackupCommand extends Command
{
    protected $signature = 'backup:create';
    protected $description = 'Create an AES-256 encrypted system backup archive (database, storage reports, audit data, and manifest)';

    public function handle(BackupService $backupService): int
    {
        $this->info('Starting automated AES-256 encrypted system backup...');

        try {
            $result = $backupService->createBackup();

            $this->info("✅ System backup created successfully!");
            $this->table(
                ['Attribute', 'Value'],
                [
                    ['Filename', $result['filename']],
                    ['Location', $result['path']],
                    ['File Size', $backupService->getSummaryStats()['total_size_formatted']],
                    ['Encryption', 'AES-256-CBC'],
                    ['Manifest Hash', substr($result['manifest']['sha256_hash'] ?? 'N/A', 0, 16) . '...'],
                    ['Created At', $result['created_at']],
                ]
            );

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("❌ Backup failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
