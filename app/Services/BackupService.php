<?php

namespace App\Services;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Services\Admin\AuditLogService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

class BackupService
{
    protected string $backupDir;

    protected string $cipher = 'aes-256-cbc';

    public function __construct(protected AuditLogService $auditLogService)
    {
        $this->backupDir = storage_path('app/backups');
        if (! File::exists($this->backupDir)) {
            File::makeDirectory($this->backupDir, 0755, true, true);
        }
    }

    /**
     * Create an encrypted backup archive containing database dump, storage files, and manifest.json.
     */
    public function createBackup(): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        $tempDir = storage_path("app/temp_backup_{$timestamp}");
        File::makeDirectory($tempDir, 0755, true, true);

        try {
            $dbDumpPath = $this->exportDatabase($tempDir);
            $storageFiles = $this->collectStorageFiles($tempDir);

            // Hash payload contents (DB dump + storage) before sealing the manifest.
            $contentHash = $this->computePayloadHash($tempDir);

            $manifest = [
                'created_at' => date('Y-m-d H:i:s'),
                'timestamp' => $timestamp,
                'app_name' => config('app.name'),
                'app_version' => '1.0.0',
                'laravel_version' => app()->version(),
                'database_driver' => DB::connection()->getDriverName(),
                'db_dump_file' => basename($dbDumpPath),
                'storage_files' => $storageFiles,
                'encrypted' => true,
                'sha256_hash' => $contentHash,
                'hash_algorithm' => 'sha256-payload-tree',
            ];

            File::put($tempDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

            $zipPath = storage_path("app/temp_backup_{$timestamp}.zip");
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception("Could not create ZIP archive at {$zipPath}");
            }

            foreach (File::allFiles($tempDir) as $file) {
                $relativePath = substr($file->getPathname(), strlen($tempDir) + 1);
                $zip->addFile($file->getPathname(), $relativePath);
            }
            $zip->close();

            $rawZipContent = File::get($zipPath);
            $key = substr(hash('sha256', config('app.key')), 0, 32);
            $iv = random_bytes(16);

            $encryptedData = openssl_encrypt($rawZipContent, $this->cipher, $key, OPENSSL_RAW_DATA, $iv);
            if ($encryptedData === false) {
                throw new Exception('OpenSSL encryption failed.');
            }

            $finalFilename = "backup_{$timestamp}.enc";
            $finalFilePath = "{$this->backupDir}/{$finalFilename}";
            File::put($finalFilePath, $iv.$encryptedData);

            File::deleteDirectory($tempDir);
            File::delete($zipPath);
            $this->pruneOldBackups();

            $this->auditLogService->record(
                AuditCategory::Settings,
                AuditEventType::Created,
                'Backup Created',
                AuditSeverity::Info,
                null,
                null,
                null,
                [
                    'filename' => $finalFilename,
                    'size' => File::size($finalFilePath),
                    'sha256_hash' => $contentHash,
                ],
                "Encrypted system backup '{$timestamp}.enc' generated successfully."
            );

            return [
                'success' => true,
                'filename' => $finalFilename,
                'path' => $finalFilePath,
                'size' => File::size($finalFilePath),
                'created_at' => date('Y-m-d H:i:s'),
                'manifest' => $manifest,
            ];
        } catch (Exception $e) {
            if (File::exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }
            if (isset($zipPath) && File::exists($zipPath)) {
                File::delete($zipPath);
            }
            throw $e;
        }
    }

    /**
     * Decrypt, recompute payload SHA-256, and compare against the sealed manifest hash.
     *
     * @return array{valid: bool, filename: string, expected_hash: ?string, computed_hash: ?string, message: string, manifest: ?array}
     */
    public function verifyBackup(string $filePath): array
    {
        [$tempZipPath, $tempExtractDir, $manifest] = $this->decryptAndExtract($filePath);

        try {
            $expected = $manifest['sha256_hash'] ?? null;
            if (! is_string($expected) || $expected === '') {
                throw new Exception('Corrupted backup: invalid or missing sha256_hash in manifest.');
            }

            $computed = $this->computePayloadHash($tempExtractDir);
            $valid = hash_equals($expected, $computed);

            return [
                'valid' => $valid,
                'filename' => basename($filePath),
                'expected_hash' => $expected,
                'computed_hash' => $computed,
                'message' => $valid
                    ? 'Backup integrity verified: payload SHA-256 matches manifest.'
                    : 'Backup integrity check failed: payload SHA-256 does not match manifest.',
                'manifest' => $manifest,
            ];
        } finally {
            if (File::exists($tempZipPath)) {
                File::delete($tempZipPath);
            }
            if (File::exists($tempExtractDir)) {
                File::deleteDirectory($tempExtractDir);
            }
        }
    }

    /**
     * Restore an encrypted backup archive after integrity verification.
     */
    public function restoreBackup(string $filePath): array
    {
        [$tempZipPath, $tempExtractDir, $manifest] = $this->decryptAndExtract($filePath);

        try {
            $expected = $manifest['sha256_hash'] ?? null;
            if (! is_string($expected) || $expected === '') {
                throw new Exception('Corrupted backup: invalid or missing sha256_hash in manifest.');
            }

            $computed = $this->computePayloadHash($tempExtractDir);
            if (! hash_equals($expected, $computed)) {
                throw new Exception('Backup integrity check failed: payload SHA-256 does not match manifest. Restore aborted.');
            }

            $storageDir = $tempExtractDir.'/storage';
            if (File::exists($storageDir)) {
                foreach (['reports', 'exports', 'audit', 'evidence'] as $folder) {
                    $src = "{$storageDir}/{$folder}";
                    $dst = storage_path("app/{$folder}");
                    if (File::exists($src)) {
                        File::copyDirectory($src, $dst);
                    }
                }
            }

            $dbDumpFile = $tempExtractDir.'/'.($manifest['db_dump_file'] ?? 'database.sql');
            if (File::exists($dbDumpFile)) {
                $this->importDatabase($dbDumpFile);
            }

            File::delete($tempZipPath);
            File::deleteDirectory($tempExtractDir);

            $this->auditLogService->record(
                AuditCategory::Settings,
                AuditEventType::Override,
                'Backup Restored',
                AuditSeverity::Critical,
                null,
                null,
                null,
                [
                    'filename' => basename($filePath),
                    'sha256_hash' => $expected,
                ],
                "System state restored from encrypted backup archive '".basename($filePath)."'."
            );

            return [
                'success' => true,
                'message' => 'Backup restored successfully.',
                'manifest' => $manifest,
            ];
        } catch (Exception $e) {
            if (File::exists($tempZipPath)) {
                File::delete($tempZipPath);
            }
            if (File::exists($tempExtractDir)) {
                File::deleteDirectory($tempExtractDir);
            }
            throw $e;
        }
    }

    /**
     * List all available backup archives with metadata.
     */
    public function listBackups(): array
    {
        $files = File::files($this->backupDir);
        $backups = [];

        foreach ($files as $file) {
            if ($file->getExtension() === 'enc') {
                $backups[] = [
                    'filename' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'size_bytes' => $file->getSize(),
                    'size_formatted' => $this->formatBytes($file->getSize()),
                    'created_at' => date('Y-m-d H:i:s', $file->getMTime()),
                ];
            }
        }

        usort($backups, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    /**
     * Get backup storage summary statistics for UI health cards.
     */
    public function getSummaryStats(): array
    {
        $backups = $this->listBackups();
        $totalBytes = array_sum(array_column($backups, 'size_bytes'));
        $lastBackup = $backups[0]['created_at'] ?? 'Never';

        return [
            'total_backups' => count($backups),
            'total_size_bytes' => $totalBytes,
            'total_size_formatted' => $this->formatBytes($totalBytes),
            'last_backup_at' => $lastBackup,
            'retention_days' => 30,
            'encryption_cipher' => 'AES-256-CBC',
        ];
    }

    /**
     * Decrypt an .enc archive and extract to a temp directory.
     *
     * @return array{0: string, 1: string, 2: array}
     */
    protected function decryptAndExtract(string $filePath): array
    {
        if (! File::exists($filePath)) {
            throw new Exception("Backup file not found at: {$filePath}");
        }

        $rawContent = File::get($filePath);
        if (strlen($rawContent) < 17) {
            throw new Exception('Invalid encrypted backup archive file.');
        }

        $iv = substr($rawContent, 0, 16);
        $encryptedData = substr($rawContent, 16);
        $key = substr(hash('sha256', config('app.key')), 0, 32);

        $decryptedZip = openssl_decrypt($encryptedData, $this->cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($decryptedZip === false) {
            throw new Exception('Decryption failed. Invalid encryption key or corrupted backup archive.');
        }

        $timestamp = date('Y-m-d_H-i-s').'_'.bin2hex(random_bytes(4));
        $tempZipPath = storage_path("app/temp_restore_{$timestamp}.zip");
        $tempExtractDir = storage_path("app/temp_restore_{$timestamp}");

        File::put($tempZipPath, $decryptedZip);
        File::makeDirectory($tempExtractDir, 0755, true, true);

        $zip = new ZipArchive;
        if ($zip->open($tempZipPath) !== true) {
            File::delete($tempZipPath);
            File::deleteDirectory($tempExtractDir);
            throw new Exception('Failed to open decrypted ZIP archive.');
        }
        $zip->extractTo($tempExtractDir);
        $zip->close();

        $manifestFile = $tempExtractDir.'/manifest.json';
        if (! File::exists($manifestFile)) {
            File::delete($tempZipPath);
            File::deleteDirectory($tempExtractDir);
            throw new Exception('Corrupted backup: manifest.json missing inside archive.');
        }

        $manifest = json_decode(File::get($manifestFile), true);
        if (! is_array($manifest)) {
            File::delete($tempZipPath);
            File::deleteDirectory($tempExtractDir);
            throw new Exception('Corrupted backup: invalid manifest.json format.');
        }

        return [$tempZipPath, $tempExtractDir, $manifest];
    }

    /**
     * Stable SHA-256 over relative path → file hash pairs (excludes manifest.json).
     */
    public function computePayloadHash(string $directory): string
    {
        $hashes = [];

        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($directory, DIRECTORY_SEPARATOR)) + 1));
            if ($relative === 'manifest.json') {
                continue;
            }
            $hashes[$relative] = hash_file('sha256', $file->getPathname());
        }

        ksort($hashes);

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    protected function exportDatabase(string $targetDir): string
    {
        $driver = DB::connection()->getDriverName();
        $dumpPath = "{$targetDir}/database.sql";

        if ($driver === 'sqlite') {
            $sqlitePath = DB::connection()->getDatabaseName();
            if (File::exists($sqlitePath)) {
                File::copy($sqlitePath, "{$targetDir}/database.sqlite");

                return "{$targetDir}/database.sqlite";
            }
        }

        $tables = DB::select('SHOW TABLES');
        $dbName = DB::connection()->getDatabaseName();
        $keyName = "Tables_in_{$dbName}";

        $sql = "-- QA Platform Encrypted Database Dump\n";
        $sql .= '-- Created: '.date('Y-m-d H:i:s')."\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $tableObj) {
            $tableName = array_values((array) $tableObj)[0];
            $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`")[0];
            $createTableSql = array_values((array) $createTable)[1] ?? reset($createTable);
            $sql .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
            $sql .= $createTableSql.";\n\n";

            $rows = DB::table($tableName)->get();
            foreach ($rows as $row) {
                $rowArray = (array) $row;
                $values = array_map(function ($val) {
                    if ($val === null) {
                        return 'NULL';
                    }

                    return DB::getPdo()->quote($val);
                }, $rowArray);
                $sql .= "INSERT INTO `{$tableName}` (`".implode('`, `', array_keys($rowArray)).'`) VALUES ('.implode(', ', $values).");\n";
            }
            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        File::put($dumpPath, $sql);

        return $dumpPath;
    }

    protected function importDatabase(string $dumpFile): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' && str_ends_with($dumpFile, '.sqlite')) {
            $sqlitePath = DB::connection()->getDatabaseName();
            File::copy($dumpFile, $sqlitePath);

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        $sql = File::get($dumpFile);
        DB::unprepared($sql);

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    protected function collectStorageFiles(string $tempDir): array
    {
        $collected = [];
        $storageFolders = ['reports', 'exports', 'audit', 'evidence'];

        foreach ($storageFolders as $folder) {
            $srcPath = storage_path("app/{$folder}");
            if (File::exists($srcPath)) {
                $dstPath = "{$tempDir}/storage/{$folder}";
                File::copyDirectory($srcPath, $dstPath);
                foreach (File::allFiles($dstPath) as $file) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dstPath) + 1));
                    $collected[] = "storage/{$folder}/{$relative}";
                }
            }
        }

        return $collected;
    }

    protected function pruneOldBackups(): void
    {
        $files = File::files($this->backupDir);
        $threshold = strtotime('-30 days');

        foreach ($files as $file) {
            if ($file->getExtension() === 'enc' && $file->getMTime() < $threshold) {
                File::delete($file->getPathname());
            }
        }
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
