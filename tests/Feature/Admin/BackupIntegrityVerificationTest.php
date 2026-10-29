<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupIntegrityVerificationTest extends TestCase
{
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = User::factory()->superAdmin()->create(['email_verified_at' => now()]);
    }

    public function test_verify_command_accepts_valid_backup(): void
    {
        $result = app(BackupService::class)->createBackup();

        $this->artisan('backup:verify', ['file' => $result['filename']])
            ->expectsOutputToContain('Backup integrity verified')
            ->assertExitCode(0);
    }

    public function test_restore_rejects_tampered_payload_hash(): void
    {
        $service = app(BackupService::class);
        $result = $service->createBackup();

        // Decrypt, mutate a payload file hash by rewriting manifest expected hash, re-encrypt.
        $raw = File::get($result['path']);
        $iv = substr($raw, 0, 16);
        $key = substr(hash('sha256', config('app.key')), 0, 32);
        $zipBinary = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $this->assertNotFalse($zipBinary);

        $zipPath = storage_path('app/temp_tamper.zip');
        $extract = storage_path('app/temp_tamper_extract');
        File::put($zipPath, $zipBinary);
        File::makeDirectory($extract, 0755, true, true);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $zip->extractTo($extract);
        $zip->close();

        $manifest = json_decode(File::get($extract.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['sha256_hash'] = str_repeat('0', 64);
        File::put($extract.'/manifest.json', json_encode($manifest));

        $tamperedZip = storage_path('app/temp_tampered.zip');
        $out = new \ZipArchive;
        $this->assertTrue($out->open($tamperedZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach (File::allFiles($extract) as $file) {
            $rel = substr($file->getPathname(), strlen($extract) + 1);
            $out->addFile($file->getPathname(), $rel);
        }
        $out->close();

        $newIv = random_bytes(16);
        $encrypted = openssl_encrypt(File::get($tamperedZip), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $newIv);
        $tamperedEnc = storage_path('app/backups/backup_tampered.enc');
        File::put($tamperedEnc, $newIv.$encrypted);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Backup integrity check failed');
        $service->restoreBackup($tamperedEnc);
    }

    public function test_admin_can_verify_backup_from_ui(): void
    {
        $result = app(BackupService::class)->createBackup();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.backups.verify', ['filename' => $result['filename']]))
            ->assertRedirect(route('admin.backups.index'))
            ->assertSessionHas('status');
    }
}
