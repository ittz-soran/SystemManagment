<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `shop:provision` — a new shop's folder, made from nothing.
 *
 * PANEL_DOC Section 11 step 1. The assertions worth having are not that the
 * files exist but that the shop they describe actually boots as itself, so the
 * last test here spawns a real process and asks it, the same way
 * ShopIsolationTest does and for the same reason: SHOP_HOME is a constant, and
 * a constant-driven mechanism can only honestly be tested the way it runs.
 */
class ShopProvisionTest extends TestCase
{
    private string $root;

    /** Set when this test had to invent the compiled assets — see assetsExist(). */
    private bool $madeAssets = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/provision-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);

        $this->assetsExist();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);

        if ($this->madeAssets) {
            $this->rmrf(base_path('public/build'));
        }

        parent::tearDown();
    }

    /**
     * public/build is generated, not committed (.gitignore), so on a clean
     * checkout there is nothing to copy and the command refuses — correctly: a
     * shop without a stylesheet is broken. This test is about provisioning
     * rather than about assets, so it stands one up and takes it away again,
     * and never touches a real build that is already there.
     */
    private function assetsExist(): void
    {
        if (is_dir(base_path('public/build'))) {
            return;
        }

        mkdir(base_path('public/build/assets'), 0755, true);
        file_put_contents(base_path('public/build/manifest.json'), '{}');
        file_put_contents(base_path('public/build/assets/app.css'), '/* stand-in */');

        $this->madeAssets = true;
    }

    private function provision(string $name, array $options = []): int
    {
        return Artisan::call('shop:provision', array_merge([
            'name' => $name,
            '--home' => $this->root.'/shops/'.$name,
            '--public' => $this->root.'/public_html/'.$name,
        ], $options));
    }

    private function home(string $name): string
    {
        return $this->root.'/shops/'.$name;
    }

    private function public(string $name): string
    {
        return $this->root.'/public_html/'.$name;
    }

    public function test_it_makes_everything_a_shop_needs(): void
    {
        $this->assertSame(0, $this->provision('bazaar'));

        foreach ([
            '.env',
            'artisan',
            'bootstrap/app.php',
            'bootstrap/cache',
            'storage/app/backups',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
        ] as $path) {
            $this->assertFileOrDirectoryExists($this->home('bazaar').'/'.$path);
        }

        foreach (['index.php', '.htaccess', 'build'] as $path) {
            $this->assertFileOrDirectoryExists($this->public('bazaar').'/'.$path);
        }
    }

    /**
     * APP_KEY is what encrypts the staff's authenticator secrets. Two shops
     * sharing one would let either read the other's.
     */
    public function test_every_shop_gets_its_own_app_key(): void
    {
        $this->provision('alpha');
        $this->provision('beta');

        $alpha = $this->envValue('alpha', 'APP_KEY');
        $beta = $this->envValue('beta', 'APP_KEY');

        $this->assertNotSame('', $alpha);
        $this->assertStringStartsWith('base64:', $alpha);
        $this->assertNotSame($alpha, $beta);
    }

    /**
     * The whole point of the shared codebase: this file is the only thing on
     * the web that is the shop's own, and all it does is say which shop it is.
     */
    public function test_the_front_controller_and_artisan_name_this_shop(): void
    {
        $this->provision('bazaar');

        foreach (['index.php' => $this->public('bazaar'), 'artisan' => $this->home('bazaar')] as $file => $dir) {
            $contents = (string) file_get_contents($dir.'/'.$file);

            $this->assertStringContainsString("define('SHOP_HOME', '".$this->home('bazaar')."')", $contents);
            $this->assertStringContainsString(base_path('bootstrap/app.php'), $contents);
            $this->assertStringContainsString(base_path('vendor/autoload.php'), $contents);
        }
    }

    /**
     * config:cache and route:cache re-require bootstrapPath('app.php') to build
     * a fresh application. Plain require, never require_once, or they get the
     * last one back instead of a new one.
     */
    public function test_the_shop_has_its_own_bootstrap_file_deferring_to_the_shared_one(): void
    {
        $this->provision('bazaar');

        $contents = (string) file_get_contents($this->home('bazaar').'/bootstrap/app.php');

        $this->assertStringContainsString("return require '".base_path('bootstrap/app.php')."';", $contents);
        $this->assertStringNotContainsString('require_once', $contents);
    }

    /**
     * PANEL_DOC Section 6 says a trial runs `unlicensed` — full function, no
     * banner. That is only true if the shop's own .env blanks the public key,
     * because this codebase ships one as a committed default. Without --trial a
     * keyless shop is `missing`, which is read-only from its first minute.
     */
    public function test_a_trial_shop_blanks_the_public_key_and_a_plain_one_does_not(): void
    {
        $this->provision('trial', ['--trial' => true]);
        $this->provision('plain');

        $this->assertStringContainsString("\nLICENCE_PUBLIC_KEY=\n", $this->env('trial'));
        $this->assertStringNotContainsString('LICENCE_PUBLIC_KEY', $this->env('plain'));

        $this->assertSame('', $this->envValue('trial', 'LICENCE_KEY'));
        $this->assertSame('', $this->envValue('plain', 'LICENCE_KEY'));
    }

    public function test_a_delivered_licence_is_written(): void
    {
        $this->provision('bazaar', ['--licence' => 'eyJzaG9wIjoiQmF6YWFyIn0.signature']);

        $this->assertSame('eyJzaG9wIjoiQmF6YWFyIn0.signature', $this->envValue('bazaar', 'LICENCE_KEY'));
    }

    /**
     * Provisioning over a live shop would replace its .env — a new APP_KEY,
     * which is what decrypts every staff member's authenticator secret, and a
     * blank licence.
     */
    public function test_it_refuses_a_folder_that_already_has_something_in_it(): void
    {
        $this->provision('bazaar');
        $before = $this->env('bazaar');

        $this->assertSame(1, $this->provision('bazaar'));
        $this->assertSame($before, $this->env('bazaar'), 'the existing .env was left alone');
    }

    /** An empty folder is fine — cPanel makes one the moment a subdomain is added. */
    public function test_an_empty_folder_is_not_in_the_way(): void
    {
        mkdir($this->home('bazaar'), 0755, true);
        mkdir($this->public('bazaar'), 0755, true);

        $this->assertSame(0, $this->provision('bazaar'));
    }

    public function test_it_refuses_a_name_that_cannot_be_a_folder_or_a_subdomain(): void
    {
        foreach (['../escape', 'Bazaar', 'has space', '', 'a'.str_repeat('b', 40)] as $name) {
            $this->assertSame(1, $this->provision($name), "[{$name}] should have been refused");
        }

        $this->assertDirectoryDoesNotExist($this->root.'/shops/../escape');
    }

    /**
     * A half-made shop is worse than none, because it looks provisioned. The
     * missing build folder is the failure that is easiest to arrange.
     */
    public function test_a_failure_removes_everything_that_run_had_made(): void
    {
        $build = base_path('public/build');
        $moved = $build.'-moved-'.bin2hex(random_bytes(4));
        rename($build, $moved);

        try {
            $this->assertSame(1, $this->provision('bazaar'));

            $this->assertDirectoryDoesNotExist($this->home('bazaar'));
            $this->assertDirectoryDoesNotExist($this->public('bazaar'));
        } finally {
            rename($moved, $build);
        }
    }

    /**
     * The one that matters: the shop the command described actually boots, off
     * the shared codebase, as itself.
     *
     * A real process, because SHOP_HOME is a constant.
     */
    public function test_the_provisioned_shop_boots_as_itself(): void
    {
        $this->provision('bazaar');

        $script = $this->root.'/ask.php';
        file_put_contents($script, sprintf(
            '<?php define("SHOP_HOME", %s); define("SHOP_PUBLIC", %s); require %s; $app = require %s; echo json_encode([
                "env" => $app->environmentPath(),
                "storage" => $app->storagePath(),
                "bootstrap" => $app->bootstrapPath(),
                "public" => $app->publicPath(),
                "base" => $app->basePath(),
            ]);',
            var_export($this->home('bazaar'), true),
            var_export($this->public('bazaar'), true),
            var_export(base_path('vendor/autoload.php'), true),
            var_export($this->home('bazaar').'/bootstrap/app.php', true),
        ));

        $process = new Process([PHP_BINARY, $script]);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'the provisioned shop did not boot: '.$process->getErrorOutput(),
        );

        $paths = json_decode($process->getOutput(), true) ?? [];

        $this->assertSame($this->home('bazaar'), $paths['env']);
        $this->assertSame($this->home('bazaar').'/storage', $paths['storage']);
        $this->assertSame($this->home('bazaar').'/bootstrap', $paths['bootstrap']);
        $this->assertSame($this->public('bazaar'), $paths['public']);

        // And the code it runs is the shared one, not a copy.
        $this->assertSame(base_path(), $paths['base']);
    }

    private function env(string $name): string
    {
        return (string) file_get_contents($this->home($name).'/.env');
    }

    private function envValue(string $name, string $key): string
    {
        preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $this->env($name), $matches);

        return trim($matches[1] ?? '');
    }

    private function assertFileOrDirectoryExists(string $path): void
    {
        $this->assertTrue(
            file_exists($path),
            "[{$path}] was not made, and a shop needs it.",
        );
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            is_dir($path.'/'.$entry) && ! is_link($path.'/'.$entry)
                ? $this->rmrf($path.'/'.$entry)
                : @unlink($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
