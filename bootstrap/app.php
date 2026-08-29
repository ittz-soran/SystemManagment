<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);

        // Section 8c: language and theme apply to every page, including the
        // login screen, so this runs on the whole web group. So do the security
        // headers — the login screen is the page most worth protecting.
        $middleware->web(append: [
            \App\Http\Middleware\SetUserPreferences::class,
            \App\Http\Middleware\SecurityHeaders::class,

            // Only does anything on an install sold with STORAGE_LIMIT_MB set,
            // and even then only refuses writes once the space is actually
            // gone. Reading, printing, deleting and Settings always pass.
            \App\Http\Middleware\EnforceStorageQuota::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
