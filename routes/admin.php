<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FindingController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RepositoryController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\ReviewJobController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SrsDocumentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\SystemHealthController;
use Illuminate\Support\Facades\Route;

// All authenticated roles can view dashboard
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

Route::middleware('permission:reviews.view')->group(function () {
    Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('/reviews/data', [ReviewController::class, 'data'])->name('reviews.data');
    Route::get('/reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
});

// Security-sensitive actions additionally require password reauthentication (SEC-009)
Route::middleware(['permission:reviews.override', 'password.confirm'])->group(function () {
    Route::post('/reviews/{review}/override', [ReviewController::class, 'override'])->name('reviews.override');
});

Route::middleware('permission:findings.view')->group(function () {
    Route::get('/findings', [FindingController::class, 'index'])->name('findings.index');
    Route::get('/findings/data', [FindingController::class, 'data'])->name('findings.data');
    Route::get('/findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
});

Route::middleware(['permission:findings.manage', 'password.confirm'])->group(function () {
    Route::post('/findings/{finding}/false-positive', [FindingController::class, 'markFalsePositive'])->name('findings.false-positive');
    Route::post('/findings/{finding}/accept', [FindingController::class, 'accept'])->name('findings.accept');
});

Route::middleware('permission:projects.view')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/data', [ProjectController::class, 'data'])->name('projects.data');
});

Route::middleware('permission:repositories.view')->group(function () {
    Route::get('/repositories', [RepositoryController::class, 'index'])->name('repositories.index');
    Route::get('/repositories/data', [RepositoryController::class, 'data'])->name('repositories.data');
});

// Project & Repository mutations
Route::middleware('permission:projects.manage')->group(function () {
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
});

Route::middleware('permission:repositories.manage')->group(function () {
    Route::get('/repositories/discover', [RepositoryController::class, 'discover'])->name('repositories.discover');
    Route::post('/repositories', [RepositoryController::class, 'store'])->name('repositories.store');
    Route::put('/repositories/{repository}', [RepositoryController::class, 'update'])->name('repositories.update');
    Route::delete('/repositories/{repository}', [RepositoryController::class, 'destroy'])->name('repositories.destroy');
    Route::patch('/repositories/{repository}/connect', [RepositoryController::class, 'connect'])->name('repositories.connect');
    Route::patch('/repositories/{repository}/activate', [RepositoryController::class, 'activate'])
        ->middleware('password.confirm')
        ->name('repositories.activate');
    Route::patch('/repositories/{repository}/pause', [RepositoryController::class, 'pause'])->name('repositories.pause');
    Route::patch('/repositories/{repository}/resume', [RepositoryController::class, 'resume'])->name('repositories.resume');
    Route::post('/repositories/{repository}/review-now', [RepositoryController::class, 'reviewNow'])->name('repositories.review-now');
    Route::post('/repositories/{repository}/protect-branches', [RepositoryController::class, 'protectBranches'])->name('repositories.protect-branches');
});
// Objectives & SRS Management (Section 6)
Route::middleware('permission:srs.view')->group(function () {
    Route::get('/srs', [SrsDocumentController::class, 'index'])->name('srs.index');
    Route::get('/srs/{srsDocument}', [SrsDocumentController::class, 'show'])->name('srs.show');
});

Route::middleware('permission:srs.manage')->group(function () {
    Route::post('/srs', [SrsDocumentController::class, 'store'])->name('srs.store');
    Route::post('/srs/{srsDocument}/extract', [SrsDocumentController::class, 'extract'])->name('srs.extract');
    Route::post('/srs/{srsDocument}/replace', [SrsDocumentController::class, 'replace'])->name('srs.replace');
    Route::put('/srs/{srsDocument}/checklist', [SrsDocumentController::class, 'updateChecklist'])->name('srs.checklist.update');
    Route::post('/srs/{srsDocument}/submit', [SrsDocumentController::class, 'submit'])->name('srs.submit');
    Route::delete('/srs/{srsDocument}', [SrsDocumentController::class, 'destroy'])->name('srs.destroy');
});

// Approval governs which requirements block merges, so it is reauthenticated (SEC-009)
Route::middleware(['permission:srs.approve', 'password.confirm'])->group(function () {
    Route::post('/srs/{srsDocument}/approve', [SrsDocumentController::class, 'approve'])->name('srs.approve');
    Route::post('/srs/{srsDocument}/reject', [SrsDocumentController::class, 'reject'])->name('srs.reject');
});

// Reports
Route::middleware('permission:reports.view')->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/data', [ReportController::class, 'data'])->name('reports.data');
    Route::get('/reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');
    Route::get('/reports/{report}/stream', [ReportController::class, 'stream'])->name('reports.stream');
    Route::get('/exports/reviews', [ExportController::class, 'reviews'])->name('exports.reviews');
});

Route::middleware('permission:findings.view')->group(function () {
    Route::get('/exports/findings', [ExportController::class, 'findings'])->name('exports.findings');
});

// Audit Logs
Route::middleware('permission:audit.view')->group(function () {
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/analytics', [AuditLogController::class, 'analytics'])->name('audit-logs.analytics');
    Route::get('/audit-logs/analytics/data', [AuditLogController::class, 'analyticsData'])->name('audit-logs.analytics.data');
    Route::get('/audit-logs/data', [AuditLogController::class, 'data'])->name('audit-logs.data');
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
});

Route::middleware('permission:system.health.view')->group(function () {
    Route::get('/health', [SystemHealthController::class, 'index'])->name('health.index');
    Route::get('/health/data', [SystemHealthController::class, 'data'])->name('health.data');
});

Route::middleware('permission:jobs.cancel')->group(function () {
    Route::post('/review-jobs/{reviewJob}/cancel', [ReviewJobController::class, 'cancel'])->name('review-jobs.cancel');
});

Route::middleware('permission:integrations.manage')->group(function () {
    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('/integrations', [IntegrationController::class, 'update'])
        ->middleware('password.confirm')
        ->name('integrations.update');
    Route::post('/integrations/clear', [IntegrationController::class, 'clear'])
        ->middleware('password.confirm')
        ->name('integrations.clear');
});

Route::middleware('permission:notifications.view')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
});

Route::middleware('permission:notifications.manage')->group(function () {
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
});

// Settings & User Management
Route::middleware('permission:settings.manage')->group(function () {
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('/backups/generate', [BackupController::class, 'generate'])->name('backups.generate');
    Route::post('/backups/restore', [BackupController::class, 'restore'])
        ->middleware('password.confirm')
        ->name('backups.restore');
    Route::post('/backups/{filename}/verify', [BackupController::class, 'verify'])->name('backups.verify');
    Route::get('/backups/{filename}/download', [BackupController::class, 'download'])->name('backups.download');
});

Route::middleware('permission:users.view')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/data', [UserController::class, 'data'])->name('users.data');
});

Route::middleware('permission:users.manage')->group(function () {
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

// Role Management Module
Route::middleware('permission:roles.view')->group(function () {
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/data', [RoleController::class, 'data'])->name('roles.data');
});

Route::middleware('permission:roles.manage')->group(function () {
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
});
