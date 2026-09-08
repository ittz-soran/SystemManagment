<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pushing an update out to a shop, driven the way the panel will drive it.
 *
 * Real processes, for the same reason ShopIsolationTest uses them: SHOP_HOME is
 * a constant, so a process is a shop. A test that called the command in-process
 * would be testing it against the shared codebase, which is the one situation
 * where it must refuse.
 *
 * What is really being held here is the failure PANEL_DOC Section 3 named — a
 * shop running old code against an unmigrated database. The shared folder does
 * not prevent that; it guarantees it, for the minutes or days between `git
 * pull` and somebody remembering the customers. So the shop below is built
 * deliberately stale, in both the ways a shop can be stale, and the command is
 * asked to notice.
 */
class ShopUpdateTest extends TestCase
{
    private string $home;

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir().'/shop-update-'.bin2hex(random_bytes(6));
        $this->public = $this->home.'/public';

        foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/views', 'storage/app/backups', 'public'] as $dir) {
            mkdir($this->home.'/'.$dir, 0755, true);
        }

        file_put_contents($this->home.'/.env', implode("\n", [
            'APP_NAME="Bazaar Mobile"',
            'APP_KEY='.config('app.key'),
            'APP_ENV=production',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE='.$this->home.'/shop.sqlite',
            'CACHE_STORE=array',
            'SESSION_DRIVER=array',
            'QUEUE_CONNECTION=sync',
        ]));

        touch($this->home.'/shop.sqlite');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->home);

        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $this->rmrf($path.'/'.$entry);
        }

        @rmdir($path);
    }

    /**
     * Run a command through this shop's own entry point, exactly as the panel will.
     *
     * Named runInShop() because the obvious names are all taken by the class
     * this extends: run() is final on PHPUnit's TestCase, and artisan() and
     * json() are public on Laravel's. Each one is a fatal error at load time
     * rather than a failing test, which is a slow way to learn it three times.
     */
    private function runInShop(string ...$arguments): array
    {
        $script = sprintf(
            '<?php define("SHOP_HOME", %s); define("SHOP_PUBLIC", %s); define("LARAVEL_START", microtime(true));'
            .' require %s; $app = require %s;'
            .' exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));',
            var_export($this->home, true),
            var_export($this->public, true),
            var_export(base_path('vendor/autoload.php'), true),
            var_export(base_path('bootstrap/app.php'), true),
        );

        $file = $this->home.'/artisan';
        file_put_contents($file, $script);

        /*
         * The child must not inherit the suite's own database.
         *
         * phpunit.xml exports DB_CONNECTION=sqlite and DB_DATABASE=:memory:,
         * and Dotenv never overrides a variable that is already in the
         * environment — so without this the shop quietly ignores its own .env
         * and runs against a throwaway in-memory database that dies with the
         * process. Every run then has every migration pending and none of them
         * persist, which looks like a passing test and proves nothing about
         * the shop. `false` is how Symfony removes an inherited variable.
         */
        $process = new Process([PHP_BINARY, $file, ...$arguments], null, [
            'APP_ENV' => false,
            'DB_CONNECTION' => false,
            'DB_DATABASE' => false,
            'DB_URL' => false,
        ]);
        $process->setTimeout(180);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
        ];
    }

    private function asJson(string ...$arguments): array
    {
        $result = $this->runInShop(...$arguments, ...['--json']);

        $decoded = json_decode(trim($result['out']), true);

        $this->assertIsArray(
            $decoded,
            'the command did not print JSON: '.$result['out'].$result['err'],
        );

        return $decoded;
    }

    /** @return list<string> the names of the steps it says it did */
    private function stepsDone(array $result): array
    {
        return array_column(array_filter($result['steps'], fn ($s) => $s['done']), 'step');
    }

    /**
     * The refusal that matters most.
     *
     * Run without SHOP_HOME this would migrate whatever database the shared
     * codebase's own .env happens to name — on Soran's server, potentially his
     * own shop, while he believed he was updating a customer's.
     */
    public function test_it_refuses_to_run_against_the_shared_codebase(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'shop:update', '--json']);
        $process->run();

        // A refusal exits non-zero, so the panel can tell without parsing.
        $this->assertFalse($process->isSuccessful(), 'refusing must not look like success');

        $decoded = json_decode(trim($process->getOutput()), true);

        $this->assertFalse($decoded['updated']);
        $this->assertSame('not-a-shop', $decoded['reason']);
    }

    /** A brand-new shop's database has every migration pending. */
    public function test_it_sees_a_shop_whose_database_is_behind(): void
    {
        $result = $this->asJson('shop:update', '--pretend');

        $this->assertTrue($result['updated'], $result['message']);
        $this->assertSame('Nothing was done — this was a rehearsal.', $result['message']);

        // A rehearsal changes nothing: the tables are still absent afterwards.
        $this->assertSame(
            0,
            (int) shell_exec(sprintf(
                'sqlite3 %s "select count(*) from sqlite_master where name=\'products\'" 2>/dev/null || echo 0',
                escapeshellarg($this->home.'/shop.sqlite'),
            )),
            'a rehearsal must not migrate',
        );
    }

    public function test_it_migrates_the_shop_and_says_what_it_did(): void
    {
        $result = $this->asJson('shop:update', '--no-backup');

        $this->assertTrue($result['updated'], $result['message'].json_encode($result['steps']));
        $this->assertContains('migrate', $this->stepsDone($result));
        $this->assertContains('caches', $this->stepsDone($result));
    }

    /** Running it twice is not a second update. */
    public function test_a_shop_already_up_to_date_is_left_alone(): void
    {
        $this->asJson('shop:update', '--no-backup');

        $again = $this->asJson('shop:update', '--no-backup');

        $this->assertTrue($again['updated']);
        $this->assertSame('Already up to date.', $again['message']);
        $this->assertSame([], $this->stepsDone($again), 'nothing should have been done the second time');
    }

    /**
     * The assets, and the order they arrive in.
     *
     * Every file is content-hashed, so a new build shares no filename with the
     * old — but the manifest is what names them. Written first, there is a
     * window where the shop serves a manifest pointing at files that have not
     * arrived yet: a blank stylesheet, on a live till. So the manifest goes
     * last, and this asserts the outcome of that: once the manifest is there,
     * every file it names is there too.
     */
    public function test_it_copies_the_assets_and_the_manifest_names_nothing_missing(): void
    {
        $result = $this->asJson('shop:update', '--no-backup');

        $this->assertContains('assets', $this->stepsDone($result), json_encode($result['steps']));

        $manifest = $this->public.'/build/manifest.json';
        $this->assertFileExists($manifest);

        foreach (json_decode(file_get_contents($manifest), true) as $entry) {
            foreach ([...(array) ($entry['file'] ?? []), ...($entry['css'] ?? [])] as $file) {
                $this->assertFileExists(
                    $this->public.'/build/'.$file,
                    'the manifest reached the shop naming a file that did not',
                );
            }
        }
    }

    /** A shop whose assets are current is not made to copy them again. */
    public function test_assets_already_matching_are_not_copied_twice(): void
    {
        $this->asJson('shop:update', '--no-backup');

        $stamp = filemtime($this->public.'/build/manifest.json');

        // Force a second run to have something to do, so the assets step is
        // reached at all rather than skipped with everything else.
        @unlink($this->home.'/shop.sqlite');
        touch($this->home.'/shop.sqlite');

        $again = $this->asJson('shop:update', '--no-backup');

        $this->assertTrue($again['updated'], $again['message']);
        $this->assertNotContains('assets', $this->stepsDone($again), 'the assets already matched');
        $this->assertSame($stamp, filemtime($this->public.'/build/manifest.json'));
    }

    /** The panel parses this, so a renamed key is a silent null in a customer's health row. */
    public function test_the_json_shape_is_what_the_panel_reads(): void
    {
        $result = $this->asJson('shop:update', '--no-backup');

        foreach (['updated', 'reason', 'message', 'steps'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }

        $this->assertIsBool($result['updated']);
        $this->assertIsArray($result['steps']);

        foreach ($result['steps'] as $step) {
            $this->assertArrayHasKey('step', $step);
            $this->assertArrayHasKey('done', $step);
            $this->assertArrayHasKey('detail', $step);
        }
    }
}
