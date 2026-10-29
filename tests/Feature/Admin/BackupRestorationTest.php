<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupRestorationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected string $backupStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->superAdmin = User::factory()->superAdmin()->create();

        $this->backupStoragePath = storage_path('app/backups');
    }

    public function test_creates_encrypted_backup_archive_with_manifest(): void
    {
        $backupService = app(BackupService::class);
        $result = $backupService->createBackup();

        $this->assertTrue($result['success']);
        $this->assertFileExists($result['path']);
        $this->assertStringEndsWith('.enc', $result['filename']);

        // Decrypt and verify manifest
        $rawContent = File::get($result['path']);
        $iv = substr($rawContent, 0, 16);
        $encryptedData = substr($rawContent, 16);
        $key = substr(hash('sha256', config('app.key')), 0, 32);

        $decryptedZip = openssl_decrypt($encryptedData, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $this->assertNotFalse($decryptedZip);

        // Verify ZIP contents
        $tempZip = storage_path('app/temp_test_check.zip');
        $tempExtract = storage_path('app/temp_test_extract');
        File::put($tempZip, $decryptedZip);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tempZip));
        $zip->extractTo($tempExtract);
        $zip->close();

        $this->assertFileExists($tempExtract . '/manifest.json');
        $manifest = json_decode(File::get($tempExtract . '/manifest.json'), true);
        $this->assertTrue($manifest['encrypted']);
        $this->assertNotEmpty($manifest['sha256_hash']);

        File::delete($tempZip);
        File::deleteDirectory($tempExtract);
    }

    public function test_artisan_backup_create_command_executes_successfully(): void
    {
        $this->artisan('backup:create')
            ->expectsOutputToContain('System backup created successfully!')
            ->assertExitCode(0);

        $backups = File::files($this->backupStoragePath);
        $this->assertNotEmpty($backups);
    }

    public function test_artisan_backup_restore_command_restores_data(): void
    {
        $backupService = app(BackupService::class);
        $result = $backupService->createBackup();

        $this->artisan('backup:restore', [
            'file' => $result['filename'],
            '--force' => true,
        ])
        ->expectsOutputToContain('Backup restored successfully!')
        ->assertExitCode(0);

        // Reset database schema cleanly after restore test
        $this->artisan('migrate:fresh');
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_retention_policy_prunes_archives_older_than_30_days(): void
    {
        // Create an artificial old file
        $oldFile = $this->backupStoragePath . '/backup_2025-01-01_00-00-00.enc';
        File::put($oldFile, 'dummy content');
        touch($oldFile, strtotime('-35 days'));

        $this->assertFileExists($oldFile);

        $backupService = app(BackupService::class);
        $backupService->createBackup();

        // Old file should be pruned
        $this->assertFileDoesNotExist($oldFile);
    }

    public function test_admin_can_view_backups_list_and_trigger_manual_backup(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.backups.index'));
        $response->assertStatus(200);
        $response->assertSee('Encrypted Backups');

        $generateResponse = $this->actingAs($this->superAdmin)->post(route('admin.backups.generate'));
        $generateResponse->assertRedirect(route('admin.backups.index'));
        $generateResponse->assertSessionHas('status');
    }

    public function test_super_admin_can_download_backup_archive(): void
    {
        $backupService = app(BackupService::class);
        $result = $backupService->createBackup();

        $response = $this->actingAs($this->superAdmin)->get(route('admin.backups.download', [
            'filename' => $result['filename'],
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/octet-stream');
    }

    protected function tearDown(): void
    {
        // Clean up test backup files
        if (File::exists($this->backupStoragePath)) {
            File::cleanDirectory($this->backupStoragePath);
        }

        $this->artisan('migrate:fresh');
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        parent::tearDown();
    }
}
