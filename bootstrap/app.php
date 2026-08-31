<?php

use App\Http\Middleware\EnforceLicence;
use App\Http\Middleware\EnforceStorageQuota;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetUserPreferences;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/*
|------------------------------------------------------------------------------
| One copy of the code, many shops
|------------------------------------------------------------------------------
|
| A shop used to be a whole copy of this system — 27,000 files each, and an
| update meant uploading all of them again per customer. It does not have to
| be. Nothing in here is a shop's own except four things: its .env, its
| storage folder, its compiled caches, and the small public folder its domain
| points at. Everything else is identical in every install, so it can be one
| folder that every shop reads.
|
| A shop's public/index.php names itself by defining SHOP_HOME before it loads
| this file. Nothing else has to change, and a copy with no SHOP_HOME — a
| developer's machine, a single-shop install, the test suite — carries on
| exactly as it did.
|
| The bootstrap path is the one that must not be got wrong. Laravel keeps the
| compiled config there, and compiled config holds the database password. Two
| shops sharing one bootstrap/cache means the second shop served the first
| shop's credentials, silently, and every page would look fine while doing it.
| ShopIsolationTest exists to make that failure loud.
|
| The providers list is deliberately NOT moved. Application::configure()
| resolves it while building, before any of this runs, so it stays with the
| code where it belongs — which is right: what a shop differs in is its data,
| never its service providers.
|
*/
$shop = defined('SHOP_HOME') ? rtrim((string) constant('SHOP_HOME'), '/\\') : null;

if ($shop !== null && ! is_dir($shop)) {
    throw new RuntimeException("SHOP_HOME points at [{$shop}], which is not a folder.");
}

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'admin' => EnsureAdmin::class,
        ]);

        // Section 8c: language and theme apply to every page, including the
        // login screen, so this runs on the whole web group. So do the security
        // headers — the login screen is the page most worth protecting.
        $middleware->web(append: [
            SetUserPreferences::class,
            SecurityHeaders::class,

            // Only does anything on an install sold with STORAGE_LIMIT_MB set,
            // and even then only refuses writes once the space is actually
            // gone. Reading, printing, deleting and Settings always pass.
            EnforceStorageQuota::class,

            // Only does anything on a copy that ships with a seller's public
            // key, and even then only once the licence has run out of road.
            // Reading, printing, deleting, Settings and signing in always pass.
            EnforceLicence::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

if ($shop !== null) {
    $app->useEnvironmentPath($shop)
        ->useStoragePath($shop.'/storage')
        ->useBootstrapPath($shop.'/bootstrap')
        ->usePublicPath($shop.'/public');
}

return $app;
