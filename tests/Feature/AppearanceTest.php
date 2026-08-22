<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Section 8c's Layer 2: the shop's own colours, font and logo.
 *
 * All three were saving correctly and having no visible effect, for three
 * different reasons. This file is mostly about those three reasons.
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /**
     * The bug that hid the other two: Bootstrap declares --bs-primary and
     * --bs-body-font-family in its own :root, so an override emitted before the
     * stylesheet loses on source order and the setting appears to do nothing.
     */
    public function test_the_brand_block_comes_after_the_stylesheet(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $stylesheet = strpos($html, 'app.scss') ?: strpos($html, '/build/assets/app-');
        $brand = strpos($html, '--bs-primary:');

        $this->assertNotFalse($stylesheet);
        $this->assertNotFalse($brand);
        $this->assertGreaterThan(
            $stylesheet,
            $brand,
            'The brand variables must come after the compiled stylesheet, or Bootstrap wins'
        );
    }

    public function test_the_chosen_font_reaches_the_page_with_its_quotes_intact(): void
    {
        Setting::put('font_family', "'Cairo', system-ui, sans-serif");
        Setting::flushCache();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString("--bs-body-font-family: 'Cairo', system-ui, sans-serif", $html);

        // A <style> block does not decode HTML entities, so an escaped quote
        // makes the declaration invalid and the font falls back silently.
        $this->assertStringNotContainsString('&#039;Cairo&#039;', $html);
    }

    /** Only the vetted list, which is what makes printing it unescaped safe. */
    public function test_an_unlisted_font_falls_back_rather_than_being_printed(): void
    {
        Setting::put('font_family', 'x; } body { display: none } .a {');
        Setting::flushCache();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('display: none', $html);
        $this->assertStringContainsString('Noto Sans Arabic', $html);
    }

    /** The four offered fonts are self-hosted, not fetched from Google. */
    public function test_every_offered_font_is_actually_bundled(): void
    {
        $stylesheet = collect(glob(public_path('build/assets/*.css')))
            ->map(fn (string $file) => file_get_contents($file))
            ->implode('');

        foreach (['Noto Sans Arabic', 'Cairo', 'Vazirmatn', 'Tajawal'] as $family) {
            $this->assertStringContainsString(
                $family,
                $stylesheet,
                "{$family} is offered on the settings page but never loaded, so choosing it changes nothing"
            );
        }

        // And they are files this app serves, not a request to a font CDN.
        $this->assertStringNotContainsString('fonts.googleapis.com', $stylesheet);
    }

    /**
     * Bootstrap 5.3 compiles .btn-primary against $primary at build time, so the
     * variable alone leaves every button the stock blue.
     */
    public function test_a_new_primary_colour_reaches_the_buttons_not_just_the_variable(): void
    {
        Setting::put('primary_color', '#198754');
        Setting::flushCache();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('--bs-primary: #198754', $html);
        $this->assertStringContainsString('--bs-primary-rgb: 25, 135, 84', $html);

        // The part that was missing.
        $this->assertStringContainsString('.btn-primary', $html);
        $this->assertStringContainsString('--bs-btn-bg: #198754', $html);
        $this->assertStringContainsString('--bs-btn-hover-bg: #157347', $html);
        $this->assertStringNotContainsString('--bs-btn-bg: #0d6efd', $html);
    }

    public function test_the_secondary_colour_reaches_its_buttons_and_badges(): void
    {
        Setting::put('secondary_color', '#6610f2');
        Setting::flushCache();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('--bs-secondary: #6610f2', $html);
        $this->assertStringContainsString('.btn-outline-secondary', $html);
        $this->assertStringContainsString('background-color: #6610f2 !important', $html);
    }

    /**
     * Bootstrap's .text-secondary reads --bs-secondary-rgb, and this interface
     * uses that class for muted helper text on practically every form. Setting
     * it turned every hint and caption the brand colour, which is not what
     * anyone choosing a "secondary colour" is asking for.
     */
    public function test_the_secondary_colour_leaves_muted_helper_text_alone(): void
    {
        Setting::put('secondary_color', '#6610f2');
        Setting::flushCache();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('--bs-secondary-rgb', $html);
    }

    /** A pale brand colour needs dark text on it, not the white Bootstrap hardcodes. */
    public function test_a_light_colour_gets_readable_text_on_it(): void
    {
        Setting::put('primary_color', '#ffc107');
        Setting::flushCache();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('--bs-btn-color: #000', $html);
    }

    public function test_a_nonsense_colour_falls_back_rather_than_breaking_the_page(): void
    {
        Setting::put('primary_color', 'red; } body { display: none');
        Setting::flushCache();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('--bs-primary: #0d6efd', $html);
        $this->assertStringNotContainsString('display: none', $html);
    }

    // ---------------------------------------------------------------- the logo

    /**
     * The logo was stored as a /storage/… URL, which resolves only once
     * `php artisan storage:link` has made the symlink — and that needs
     * administrator rights on Windows, so on a normal XAMPP install it is
     * silently missing and every logo is a broken image.
     */
    public function test_an_uploaded_logo_is_served_and_shown(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)
            ->put(route('settings.update'), $this->payload([
                'shop_logo' => UploadedFile::fake()->image('logo.png', 200, 80),
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        // A disk path, not a URL that needs a symlink to work.
        $this->assertStringStartsWith('branding/', (string) setting('shop_logo'));
        $this->assertStringNotContainsString('/storage/', (string) setting('shop_logo'));

        $url = shop_logo();
        $this->assertNotNull($url);

        // It is on the page, and the page it is on can be reached signed out.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee($url, false);

        // And signed out, because the login page shows it too.
        auth()->logout();
        $this->get(route('login'))->assertOk()->assertSee(route('branding.logo'), false);
    }

    public function test_the_logo_route_needs_no_login_because_the_login_page_shows_it(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('branding/logo.png', $this->pixel());
        Setting::put('shop_logo', 'branding/logo.png');
        Setting::flushCache();

        $response = $this->get(route('branding.logo'))->assertOk();

        // Cached hard, because the URL carries a hash of the stored path and
        // therefore changes whenever the logo does.
        $this->assertStringContainsString('immutable', $response->headers->get('cache-control'));
        $this->assertStringContainsString('max-age=31536000', $response->headers->get('cache-control'));
    }

    public function test_replacing_the_logo_removes_the_old_file(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('branding/old.png', $this->pixel());
        Setting::put('shop_logo', 'branding/old.png');
        Setting::flushCache();

        $this->actingAs($this->admin)
            ->put(route('settings.update'), $this->payload([
                'shop_logo' => UploadedFile::fake()->image('new.png'),
            ]))
            ->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing('branding/old.png');
    }

    /** A setting naming a file that is gone shows nothing, not a broken image. */
    public function test_a_missing_file_is_no_logo_rather_than_a_broken_image(): void
    {
        Storage::fake('local');

        Setting::put('shop_logo', 'branding/deleted.png');
        Setting::flushCache();

        $this->assertNull(shop_logo());
        $this->get(route('branding.logo'))->assertNotFound();
    }

    /** Nothing outside the branding folder, whatever ends up in the setting. */
    public function test_the_route_will_not_serve_an_arbitrary_file(): void
    {
        Storage::fake('local');

        Setting::put('shop_logo', 'branding/../../../.env');
        Setting::flushCache();

        $this->assertNull(shop_logo());
        $this->get(route('branding.logo'))->assertNotFound();
    }

    /** An install that already had the old URL keeps its logo. */
    public function test_a_logo_saved_under_the_old_url_still_works(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('branding/legacy.png', $this->pixel());
        Setting::put('shop_logo', '/storage/branding/legacy.png');
        Setting::flushCache();

        $this->assertNotNull(shop_logo());
        $this->get(route('branding.logo'))->assertOk();
    }

    /** The URL changes with the logo, so a browser does not keep the old one. */
    public function test_the_url_changes_when_the_logo_does(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('branding/one.png', $this->pixel());
        Setting::put('shop_logo', 'branding/one.png');
        Setting::flushCache();
        $first = shop_logo();

        Storage::disk('local')->put('branding/two.png', $this->pixel());
        Setting::put('shop_logo', 'branding/two.png');
        Setting::flushCache();

        $this->assertNotSame($first, shop_logo());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'shop_name' => 'Soran Store',
            'primary_color' => '#0d6efd',
            'secondary_color' => '#6c757d',
            'font_family' => 'system-ui, sans-serif',
            'sidebar_style' => 'expanded',
            'default_theme' => 'light',
            'timezone' => 'Asia/Baghdad',
            'usd_rate' => 1320,
            'low_stock_threshold' => 5,
            'sku_prefix' => 'SS',
            'date_format' => 'Y-m-d',
            'backup_frequency' => 'daily',
            'backup_time' => '02:15',
            'backup_weekday' => 5,
            'backup_keep_daily' => 30,
            'backup_keep_monthly' => 12,
            'label_size' => '50x30',
        ], $overrides);
    }

    private function pixel(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
