<?php

use App\Http\Controllers\Api\V1\GitHubWebhookController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Worker\WorkerHeartbeatController;
use App\Http\Controllers\Api\V1\Worker\WorkerJobController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::post('/github/webhook', GitHubWebhookController::class)
    ->middleware('verify.github.webhook')
    ->name('github.webhook');

Route::middleware('auth.worker')->prefix('worker')->group(function () {
    Route::post('/heartbeat', WorkerHeartbeatController::class)->name('worker.heartbeat');
    Route::post('/jobs/next', [WorkerJobController::class, 'claimNextJob'])->name('worker.jobs.next');
    Route::post('/jobs/{job}/result', [WorkerJobController::class, 'submitResult'])->name('worker.jobs.result');
    Route::post('/jobs/{job}/result/begin', [WorkerJobController::class, 'beginResult'])->name('worker.jobs.result.begin');
    Route::post('/jobs/{job}/result/chunks', [WorkerJobController::class, 'appendResultChunk'])->name('worker.jobs.result.chunks');
    Route::post('/jobs/{job}/result/complete', [WorkerJobController::class, 'completeResult'])->name('worker.jobs.result.complete');
});
