<?php

namespace Tests\Feature;

use App\Http\Controllers\InstallController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Putting the shop on a phone's home screen.
 *
 * Installed, the shop opens on its own instead of as a page among twenty tabs,
 * and gets back the ninety pixels the browser's bars were taking. Three things
 * make that possible: a manifest, an icon, and a service worker.
 *
 * ⚠️ **The service worker is the dangerous one, and most of this file is about
 * it.** The obvious thing to do with one is make the shop work offline. That
 * would be a serious bug here: a till showing yesterday's stock out of a cache
 * is worse than a till showing an error, because the error is obvious and the
 * stale number is not. Somebody will one day think caching pages is an
 * improvement. These tests are the argument they will meet.
 */
class InstallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ---- the manifest ---------------------------------------------------

    /**
     * ⚠️ Before anybody signs in.
     *
     * A phone asks for the manifest while the login page is on the screen. Put
     * these behind `auth` and the install prompt never appears — and nothing
     * would look broken, because the shop works perfectly in a browser.
     */
    #[DataProvider('publicRoutes')]
    public function test_a_phone_can_fetch_it_without_signing_in(string $route): void
    {
        $this->get(route($route))->assertOk();
    }

    /** @return list<array{0: string}> */
    public static function publicRoutes(): array
    {
        return [
            'the manifest' => ['install.manifest'],
            'the service worker' => ['install.worker'],
        ];
    }

    /**
     * ⚠️ The shop's own name and colour, not the system's.
     *
     * One codebase serves many shops. A static `public/manifest.json` would put
     * the same name on every customer's phone — which is why this is a route.
     */
    public function test_it_carries_this_shop_and_not_another(): void
    {
        Setting::put('shop_name', 'Soran Electronics');
        Setting::put('primary_color', '#b5192f');

        $manifest = $this->get(route('install.manifest'))->assertOk()->json();

        $this->assertSame('Soran Electronics', $manifest['name']);
        $this->assertSame('#b5192f', $manifest['theme_color']);
        $this->assertSame('#b5192f', $manifest['background_color']);
        $this->assertSame('standalone', $manifest['display'],
            'Without standalone the shop opens in a browser tab and the install is pointless.');
    }

    /** A home screen has room for a short name, so it gets one. */
    public function test_a_long_shop_name_is_shortened_for_the_icon(): void
    {
        Setting::put('shop_name', 'Smart Soran Store System of Erbil');

        $manifest = $this->get(route('install.manifest'))->assertOk()->json();

        $this->assertSame('Smart Soran Store System of Erbil', $manifest['name']);
        $this->assertLessThanOrEqual(12, mb_strlen($manifest['short_name']));
    }

    /**
     * ⚠️ Scope is the shop's own folder, never the whole domain.
     *
     * Shops are installed under a path on shared hosting, sometimes two on one
     * domain. A scope of "/" would have one shop's installed app claiming the
     * other's pages.
     */
    public function test_it_claims_only_its_own_folder(): void
    {
        /*
         * ⚠️ Served from a subdirectory on purpose.
         *
         * The first version of this test read the base the same way the
         * controller does and compared the two — which passes for `scope => '/'`
         * as happily as for the right answer, because the test app sits at the
         * root and both are "/". It agreed with the bug. Forcing a subdirectory
         * is the only way to tell the two apart.
         */
        $manifest = $this->call('GET', '/soran-electronics/manifest.webmanifest', server: [
            /*
             * How a subdirectory install actually presents itself. ⚠️ Both
             * halves matter: the front controller sits below the domain root
             * AND the request carries that folder. Symfony works the base path
             * out from the common prefix of the two, so setting only SCRIPT_NAME
             * gives an empty base and this test quietly passes against a
             * hard-coded "/" — which is exactly what it did first time.
             */
            'SCRIPT_NAME' => '/soran-electronics/index.php',
            'SCRIPT_FILENAME' => '/soran-electronics/index.php',
            'PHP_SELF' => '/soran-electronics/index.php',
        ])->assertOk()->json();

        $this->assertSame('/soran-electronics/', $manifest['scope'],
            'An installed shop must claim its own folder. A scope of "/" has one '
            .'customer\'s app claiming another customer\'s shop on the same domain.');
        $this->assertSame('/soran-electronics/', $manifest['start_url']);
    }

    // ---- the icon -------------------------------------------------------

    #[DataProvider('sizes')]
    public function test_the_icon_is_a_real_image_at_the_size_it_claims(int $size): void
    {
        $png = $this->get(route('install.icon', ['size' => $size]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->streamedContent();

        $measured = getimagesizefromstring($png);

        $this->assertNotFalse($measured, 'The icon is not an image a phone could read.');
        $this->assertSame([$size, $size], [$measured[0], $measured[1]]);
    }

    /** @return list<array{0: int}> */
    public static function sizes(): array
    {
        return array_map(fn (int $size) => [$size], InstallController::SIZES);
    }

    /** Nothing else. The route draws images; it is not an image service. */
    public function test_it_refuses_a_size_it_does_not_offer(): void
    {
        $this->get(route('install.icon', ['size' => 4096]))->assertNotFound();
    }

    /**
     * ⚠️ A new logo has to reach a phone that already installed the old one.
     *
     * The icon is cached hard — a year, immutable — so the only way a change
     * arrives is a different URL. The version in that URL is a hash of what the
     * icon is drawn from.
     */
    public function test_changing_the_colour_changes_the_icon_url(): void
    {
        $before = app(InstallController::class)->iconVersion();

        Setting::put('primary_color', '#0b7a3b');
        Setting::flushCache();

        $this->assertNotSame($before, app(InstallController::class)->iconVersion());
    }

    // ---- the service worker, which is the one to be careful about --------

    /**
     * ⚠️⚠️ **The whole point of this file.**
     *
     * The worker may answer from its cache for hashed build assets — their
     * names change when their contents do, so a kept copy is the same file
     * forever. Everything else has to reach the server: a page, a search, a
     * running total, a stock figure.
     *
     * If you are here because this test failed after you taught the worker to
     * cache pages: don't. The shop already says plainly when it has lost the
     * server, and that is the honest answer to being offline. A number from
     * yesterday, shown with no indication of its age, at a counter, while
     * somebody is being served, is not.
     */
    public function test_the_worker_answers_from_cache_only_for_build_assets(): void
    {
        $worker = $this->get(route('install.worker'))->assertOk()->getContent();

        $this->assertStringContainsString('const BUILD', $worker);
        $this->assertStringContainsString('url.pathname.startsWith(BUILD)', $worker,
            'The cache must be gated on the build path.');

        // The gate returns early for everything else, before respondWith.
        $gate = strpos($worker, 'startsWith(BUILD)');
        $answers = strpos($worker, 'respondWith');

        $this->assertIsInt($gate);
        $this->assertIsInt($answers);
        $this->assertLessThan($answers, $gate,
            'The worker decides whether to answer at all BEFORE it answers. A respondWith '
            .'reached before the build-path gate would be serving pages from a cache.');

        foreach (['caches.match(', 'addAll(', 'navigationPreload', "'navigate'"] as $offline) {
            $this->assertStringNotContainsString($offline, $worker,
                'This worker precaches or serves navigations — it must not. See the note above.');
        }
    }

    /**
     * ⚠️ And the worker itself is never cached.
     *
     * It is the one file a refresh cannot fix, because it is what answers the
     * refresh. A wrong one cached for a year is a shop that cannot be updated.
     */
    public function test_the_worker_is_never_cached(): void
    {
        // The directives, not the string: the framework normalises their order
        // and adds `private` of its own, so matching the whole header exactly
        // would be testing Laravel's alphabet rather than the shop's rule.
        $header = $this->get(route('install.worker'))->assertOk()->headers->get('Cache-Control');

        foreach (['no-cache', 'no-store', 'must-revalidate'] as $directive) {
            $this->assertStringContainsString($directive, (string) $header);
        }
    }

    // ---- the pages have to ask for all of it -----------------------------

    /** @return list<array{0: string}> */
    public static function pages(): array
    {
        return [
            'the login page' => ['login'],
            'a page behind the door' => ['dashboard'],
        ];
    }

    /**
     * Both layouts, not just the one behind the login.
     *
     * A phone is most likely to be offered the install on the login page —
     * which uses the other layout, and would have been the easy one to forget.
     */
    #[DataProvider('pages')]
    public function test_every_page_asks_to_be_installable(string $route): void
    {
        if ($route !== 'login') {
            $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
        }

        $this->get(route($route))
            ->assertOk()
            ->assertSee('rel="manifest"', escape: false)
            ->assertSee('name="theme-color"', escape: false)
            ->assertSee('rel="apple-touch-icon"', escape: false);
    }
}
