<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\Admin\EnsureAdminPanelAccess;
use App\Http\Middleware\Api\AuthenticateWorker;
use App\Http\Middleware\Admin\EnsureUserRole;
use App\Http\Middleware\Admin\EnsurePermission;
use App\Http\Middleware\Api\VerifyGitHubWebhook;
use App\Http\Middleware\Auth\EnsureTwoFactorVerified;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.panel' => EnsureAdminPanelAccess::class,
            'auth.worker' => AuthenticateWorker::class,
            'role' => EnsureUserRole::class,
            'permission' => EnsurePermission::class,
            'verify.github.webhook' => VerifyGitHubWebhook::class,
            'two-factor' => EnsureTwoFactorVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*'),
        );
    })->create();
