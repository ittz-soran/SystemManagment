<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * One copy of the code, many shops, and none of them can see another.
 *
 * A shop used to be a whole copy of this system. It does not have to be:
 * nothing differs between two installs except four things — the .env, the
 * storage folder, the compiled caches, and the small public folder a domain
 * points at. bootstrap/app.php moves exactly those four when SHOP_HOME is
 * defined, and leaves everything else where it is.
 *
 * The compiled caches are the one that must not be got wrong, and the reason
 * this test spawns real processes rather than asserting against paths in
 * memory. Laravel writes the whole resolved config — database password
 * included — into bootstrap/cache/config.php. Two shops sharing that folder
 * means the second shop reads the first shop's credentials and serves the
 * first shop's data, on every page, with nothing on disk to suggest it: the
 * storage path still looks right, the .env still looks right, the shop is
 * simply someone else's.
 *
 * That failure was reproduced deliberately before this test was written. With
 * the bootstrap path left shared, a second shop reported the first shop's
 * database, shop name and administrator. Every assertion below is aimed at
 * that.
 *
 * Real processes because SHOP_HOME is a constant: it cannot be defined twice
 * in one process, and a mechanism driven by a constant can only honestly be
 * tested the way it actually runs.
 */
class ShopIsolationTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir().'/shop-'.bin2hex(random_bytes(6));

        foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/views', 'public'] as $dir) {
            mkdir($this->home.'/'.$dir, 0755, true);
        }

        file_put_contents($this->home.'/.env', implode("\n", [
            'APP_NAME="A Shop Of Its Own"',
            'APP_KEY='.config('app.key'),
            'APP_ENV=production',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=:memory:',
            'CACHE_STORE=array',
            'SESSION_DRIVER=array',
            'QUEUE_CONNECTION=sync',
        ]));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->home);

        parent::tearDown();
    }

    /** Boot the shared codebase as one shop, in its own process, and ask it about itself. */
    private function ask(string $php, bool $boot = false, ?string $public = null): array
    {
        $script = sprintf(
            '<?php define("SHOP_HOME", %s); %s require %s; $app = require %s; %s echo json_encode(%s);',
            var_export($this->home, true),
            $public === null ? '' : sprintf('define("SHOP_PUBLIC", %s);', var_export($public, true)),
            var_export(base_path('vendor/autoload.php'), true),
            var_export(base_path('bootstrap/app.php'), true),
            $boot ? '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();' : '',
            $php,
        );

        $file = $this->home.'/ask.php';
        file_put_contents($file, $script);

        $process = new Process([PHP_BINARY, $file]);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'the shared bootstrap failed to boot as a shop: '.$process->getErrorOutput(),
        );

        return json_decode($process->getOutput(), true) ?? [];
    }

    public function test_a_shop_reads_its_own_env_storage_caches_and_public_folder(): void
    {
        $paths = $this->ask('[
            "env" => $app->environmentPath(),
            "storage" => $app->storagePath(),
            "bootstrap" => $app->bootstrapPath(),
            "public" => $app->publicPath(),
        ]');

        $this->assertSame($this->home, $paths['env'], 'the .env is the shop\'s own');
        $this->assertSame($this->home.'/storage', $paths['storage']);
        $this->assertSame($this->home.'/bootstrap', $paths['bootstrap']);
        $this->assertSame($this->home.'/public', $paths['public']);
    }

    /**
     * The five compiled caches, every one of them, inside the shop.
     *
     * Named individually rather than checking the folder, because they are
     * resolved one at a time through normalizeCachePath() and a single one
     * left behind in the shared folder is a single one shared between every
     * shop on the server.
     */
    public function test_every_compiled_cache_belongs_to_the_shop_alone(): void
    {
        $caches = $this->ask('[
            "config" => $app->getCachedConfigPath(),
            "routes" => $app->getCachedRoutesPath(),
            "services" => $app->getCachedServicesPath(),
            "packages" => $app->getCachedPackagesPath(),
            "events" => $app->getCachedEventsPath(),
        ]');

        $this->assertCount(5, $caches);

        foreach ($caches as $which => $path) {
            $this->assertStringStartsWith(
                $this->home.'/bootstrap/cache/',
                $path,
                "the {$which} cache would be shared with every other shop — it holds resolved config, and resolved config holds the database password",
            );
        }
    }

    /**
     * What a shop differs in is its data, never its service providers.
     *
     * Application::configure() resolves the providers file while building,
     * before the shop paths are applied, and RegisterProviders::merge() keeps
     * that resolved path — so the list that actually boots is the code's own.
     *
     * Asserted by booting a shop and looking for a provider that only the
     * shared list names, rather than by comparing paths: getBootstrapProviders
     * Path() recomputes lazily and answers with the shop's folder, which is
     * true and beside the point. What matters is which file was read.
     */
    public function test_the_providers_that_boot_are_the_code_s_own(): void
    {
        $this->assertFileDoesNotExist(
            $this->home.'/bootstrap/providers.php',
            'the shop has no providers file of its own, so anything registered came from the shared one',
        );

        $answer = $this->ask('[
            "has_app_provider" => array_key_exists(
                App\\Providers\\AppServiceProvider::class,
                $app->getLoadedProviders(),
            ),
        ]', boot: true);

        $this->assertTrue(
            $answer['has_app_provider'],
            'AppServiceProvider is named only in the shared bootstrap/providers.php, so its absence means the shop read a providers list of its own',
        );
    }

    /**
     * A public folder somewhere else entirely.
     *
     * Hosting that will not let a document root leave public_html does not
     * stop any of this: the shop's .env, storage and compiled caches stay in
     * shops/<name> where no address reaches them, and only the six-file public
     * folder moves. The front controller says where it is.
     */
    public function test_the_public_folder_can_live_somewhere_else(): void
    {
        $elsewhere = $this->home.'-public_html/soran';
        mkdir($elsewhere, 0755, true);

        try {
            $paths = $this->ask('[
                "public" => $app->publicPath(),
                "storage" => $app->storagePath(),
                "bootstrap" => $app->bootstrapPath(),
            ]', public: $elsewhere);

            $this->assertSame($elsewhere, $paths['public'], 'the domain points here');

            // The parts that must never be on the web stay where they were.
            $this->assertSame($this->home.'/storage', $paths['storage']);
            $this->assertSame($this->home.'/bootstrap', $paths['bootstrap']);
        } finally {
            $this->rmrf(dirname($elsewhere));
        }
    }

    /** With no SHOP_HOME, nothing moves — a single-shop install is untouched. */
    public function test_an_install_that_names_no_shop_is_left_exactly_as_it_was(): void
    {
        $this->assertSame(base_path(), app()->environmentPath());
        $this->assertSame(base_path('storage'), app()->storagePath());
        $this->assertSame(base_path('bootstrap'), app()->bootstrapPath());
    }

    /** A SHOP_HOME pointing at nothing is a mistake worth saying out loud. */
    public function test_a_shop_home_that_is_not_there_refuses_to_boot(): void
    {
        $script = sprintf(
            '<?php define("SHOP_HOME", "/no/such/shop"); require %s; require %s;',
            var_export(base_path('vendor/autoload.php'), true),
            var_export(base_path('bootstrap/app.php'), true),
        );

        file_put_contents($file = $this->home.'/missing.php', $script);

        $process = new Process([PHP_BINARY, $file]);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('/no/such/shop', $process->getErrorOutput());
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $full = $path.'/'.$entry;
            is_dir($full) ? $this->rmrf($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
