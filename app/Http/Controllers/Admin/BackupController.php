<?php

namespace App\Http\Controllers\Admin;

use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Exception;

class BackupController extends AdminController
{
    public function __construct(protected BackupService $backupService)
    {
    }

    public function index(Request $request)
    {
        $allBackups = $this->backupService->listBackups();
        $stats = $this->backupService->getSummaryStats();

        $perPage = $this->perPage($request);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $total = count($allBackups);
        $results = array_slice($allBackups, ($page - 1) * $perPage, $perPage);

        $backups = new LengthAwarePaginator($results, $total, $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('admin.backups.index', compact('backups', 'stats'));
    }

    public function generate(): RedirectResponse
    {
        try {
            $result = $this->backupService->createBackup();
            return redirect()->route('admin.backups.index')
                ->with('status', "Encrypted system backup '{$result['filename']}' created successfully.");
        } catch (Exception $e) {
            return redirect()->route('admin.backups.index')
                ->with('error', "Backup generation failed: " . $e->getMessage());
        }
    }

    public function restore(Request $request): RedirectResponse
    {
        try {
            if ($request->hasFile('backup_file')) {
                $file = $request->file('backup_file');
                $tempPath = storage_path('app/temp_restore_upload_' . time() . '.enc');
                $file->move(dirname($tempPath), basename($tempPath));

                $result = $this->backupService->restoreBackup($tempPath);
                if (File::exists($tempPath)) {
                    File::delete($tempPath);
                }
            } elseif ($request->filled('filename')) {
                $filename = basename($request->input('filename'));
                $fullPath = storage_path('app/backups/' . $filename);
                $result = $this->backupService->restoreBackup($fullPath);
            } else {
                return redirect()->route('admin.backups.index')
                    ->with('error', 'No backup file selected for restoration.');
            }

            return redirect()->route('admin.backups.index')
                ->with('status', 'System backup restored successfully! Database and storage files updated.');
        } catch (Exception $e) {
            return redirect()->route('admin.backups.index')
                ->with('error', 'Restoration failed: ' . $e->getMessage());
        }
    }

    public function download(string $filename): BinaryFileResponse|RedirectResponse
    {
        $filePath = storage_path("app/backups/{$filename}");

        if (! str_ends_with($filename, '.enc') || ! file_exists($filePath)) {
            return redirect()->route('admin.backups.index')
                ->with('error', 'Backup archive file not found.');
        }

        return response()->download($filePath, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function verify(string $filename): RedirectResponse
    {
        $filename = basename($filename);
        $filePath = storage_path("app/backups/{$filename}");

        try {
            $result = $this->backupService->verifyBackup($filePath);
        } catch (Exception $e) {
            return redirect()->route('admin.backups.index')
                ->with('error', 'Verification failed: '.$e->getMessage());
        }

        if ($result['valid']) {
            return redirect()->route('admin.backups.index')
                ->with('status', $result['message']);
        }

        return redirect()->route('admin.backups.index')
            ->with('error', $result['message']);
    }
}
