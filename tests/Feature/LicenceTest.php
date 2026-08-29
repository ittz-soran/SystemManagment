<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Licence;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Whether this copy is still paid for, and still the copy it was sold as.
 *
 * The system is sold monthly, so something has to notice when it stops being
 * paid for. Most of what follows is not about a valid licence working — it is
 * about the four ways of not having one, and about what a shop can still do
 * while it does not.
 *
 * Because the honest limit is worth stating: this makes not paying a deliberate
 * act and makes copying the folder to a second shop fail. Somebody with the
 * source and the server can delete the check, and no licence written in PHP
 * can stop that.
 */
class LicenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    // Initialised, because openssl_pkey_export takes its output by reference
    // and PHP will not hand it an uninitialised typed property.
    private static string $private = '';

    private static string $public = '';

    public static function setUpBeforeClass(): void
    {
        // One keypair for the whole suite: generating RSA is slow, and every
        // test here needs the same seller.
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, self::$private);
        self::$public = openssl_pkey_get_details($resource)['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin);
    }

    /** Put a licence on this copy, as the seller would. */
    private function licensed(array $payload = [], bool $withKey = true): string
    {
        $licence = Licence::sign([
            'id' => 'TEST-0001',
            'shop' => 'Soran Store',
            'host' => null,
            'issued' => now()->toDateString(),
            'expires' => now()->addMonth()->toDateString(),
            ...$payload,
        ], self::$private);

        config([
            'licence.public_key' => $withKey ? self::$public : '',
            'licence.key' => $licence,
        ]);

        app(Licence::class)->forget();

        return $licence;
    }

    private function state(): string
    {
        app(Licence::class)->forget();

        return app(Licence::class)->state();
    }

    // =====================================================================
    // A copy that was never sold
    // =====================================================================

    public function test_without_a_public_key_there_is_no_licensing_at_all(): void
    {
        config(['licence.public_key' => '', 'licence.key' => '']);

        $this->assertFalse(app(Licence::class)->isRequired());
        $this->assertSame(Licence::UNLICENSED, $this->state());

        $this->post(route('categories.store'), ['name' => 'Cables'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('categories', ['name' => 'Cables']);

        $this->get(route('settings.edit'))->assertOk()->assertDontSee(__('Licence'), false);
    }

    // =====================================================================
    // The signature is the whole thing
    // =====================================================================

    public function test_a_licence_signed_by_the_seller_is_accepted(): void
    {
        $this->licensed();

        $this->assertSame(Licence::VALID, $this->state());
        $this->assertTrue(app(Licence::class)->allowsWriting());
    }

    public function test_one_changed_byte_in_the_signature_is_refused(): void
    {
        $licence = $this->licensed();

        [$body, $signature] = explode('.', $licence);

        /*
         * A byte flipped in the middle, not a character changed at the end.
         *
         * Base64 drops the padding bits of its final character, so changing
         * the last letter can decode to exactly the same bytes — which made
         * the first version of this test pass or fail depending on the key it
         * happened to generate. Decoding, flipping, and re-encoding says what
         * was meant.
         */
        $raw = base64_decode(strtr($signature, '-_', '+/'), true);
        $raw[64] = chr(ord($raw[64]) ^ 0xFF);

        config(['licence.key' => $body.'.'.rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]);

        $this->assertSame(Licence::INVALID, $this->state());
    }

    /** And a body somebody edited by hand, with its own signature left alone. */
    public function test_a_changed_shop_name_is_refused(): void
    {
        $licence = $this->licensed(['shop' => 'Soran Store']);

        $signature = explode('.', $licence)[1];

        $edited = rtrim(strtr(base64_encode(json_encode([
            'id' => 'TEST-0001', 'shop' => 'Somebody Else', 'host' => null,
            'issued' => now()->toDateString(), 'expires' => now()->addMonth()->toDateString(),
        ])), '+/', '-_'), '=');

        config(['licence.key' => $edited.'.'.$signature]);

        $this->assertSame(Licence::INVALID, $this->state());
    }

    /**
     * The forgery worth being sure about: keep the real signature, swap the
     * body for one that never runs out.
     */
    public function test_a_rewritten_expiry_with_the_old_signature_is_refused(): void
    {
        $licence = $this->licensed();

        $forged = rtrim(strtr(base64_encode(json_encode([
            'id' => 'TEST-0001', 'shop' => 'Soran Store', 'host' => null,
            'issued' => now()->toDateString(), 'expires' => '2099-01-01',
        ])), '+/', '-_'), '=');

        config(['licence.key' => $forged.'.'.explode('.', $licence)[1]]);

        $this->assertSame(Licence::INVALID, $this->state());
    }

    /** A licence signed by somebody else's key is not a licence for this copy. */
    public function test_another_sellers_signature_is_refused(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPrivate);

        config([
            'licence.public_key' => self::$public,
            'licence.key' => Licence::sign(['shop' => 'Free', 'expires' => '2099-01-01'], $otherPrivate),
        ]);

        $this->assertSame(Licence::INVALID, $this->state());
    }

    public function test_nothing_in_the_env_is_reported_as_missing(): void
    {
        config(['licence.public_key' => self::$public, 'licence.key' => '']);

        $this->assertSame(Licence::MISSING, $this->state());
    }

    public function test_rubbish_in_the_env_is_reported_as_unreadable(): void
    {
        config(['licence.public_key' => self::$public, 'licence.key' => 'not-a-licence']);

        $this->assertSame(Licence::INVALID, $this->state());
    }

    // =====================================================================
    // The copied folder
    // =====================================================================

    public function test_a_licence_for_another_shops_domain_is_refused(): void
    {
        $this->licensed(['host' => 'someone-else.com']);

        $this->assertSame(Licence::WRONG_HOST, $this->state());
        $this->assertFalse(app(Licence::class)->allowsWriting());
    }

    public function test_the_right_domain_is_accepted(): void
    {
        $this->licensed(['host' => 'localhost']);

        $this->assertSame(Licence::VALID, $this->state());
    }

    /** www. is the same shop. */
    public function test_the_www_prefix_is_ignored(): void
    {
        $this->licensed(['host' => 'www.localhost']);

        $this->assertSame(Licence::VALID, $this->state());
    }

    /** No domain named is a licence the seller deliberately left portable. */
    public function test_a_licence_with_no_domain_runs_anywhere(): void
    {
        $this->licensed(['host' => null]);

        $this->assertSame(Licence::VALID, $this->state());
    }

    // =====================================================================
    // The calendar
    // =====================================================================

    public function test_the_states_arrive_in_the_right_order(): void
    {
        foreach ([
            60 => Licence::VALID,
            15 => Licence::VALID,
            13 => Licence::EXPIRING,
            1 => Licence::EXPIRING,
            -1 => Licence::GRACE,
            -6 => Licence::GRACE,
            -9 => Licence::EXPIRED,
            -400 => Licence::EXPIRED,
        ] as $days => $expected) {
            $this->licensed(['expires' => now()->addDays($days)->toDateString()]);

            $this->assertSame($expected, $this->state(), "{$days} days from the date");
        }
    }

    /** A copy sold outright rather than monthly. */
    public function test_a_licence_with_no_end_date_never_expires(): void
    {
        $this->licensed(['expires' => null]);

        $this->assertSame(Licence::VALID, $this->state());
        $this->assertTrue(app(Licence::class)->allowsWriting());
    }

    /** The point of the grace days: an unpaid invoice does not stop the shop. */
    public function test_inside_the_grace_days_the_shop_still_trades(): void
    {
        $this->licensed(['expires' => now()->subDays(3)->toDateString()]);

        $this->post(route('categories.store'), ['name' => 'Cables'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('categories', ['name' => 'Cables']);
    }

    // =====================================================================
    // What stops, and what must not
    // =====================================================================

    public function test_once_it_has_run_out_nothing_new_is_saved(): void
    {
        $this->licensed(['expires' => now()->subDays(30)->toDateString()]);

        $this->post(route('categories.store'), ['name' => 'Cables'])->assertSessionHas('error');
        $this->assertDatabaseMissing('categories', ['name' => 'Cables']);
    }

    /** A shop locked out of its own records never pays another invoice. */
    public function test_once_it_has_run_out_every_screen_still_opens(): void
    {
        $this->licensed(['expires' => now()->subDays(30)->toDateString()]);

        foreach ([
            route('dashboard'), route('products.index'), route('sales.index'),
            route('customers.index'), route('reports.index'), route('settings.edit'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_once_it_has_run_out_deleting_still_works(): void
    {
        $spare = Category::create(['name' => 'Spare']);

        $this->licensed(['expires' => now()->subDays(30)->toDateString()]);

        $this->delete(route('categories.destroy', $spare))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('categories', ['id' => $spare->id]);
    }

    public function test_once_it_has_run_out_settings_can_still_be_saved(): void
    {
        $this->licensed(['expires' => now()->subDays(30)->toDateString()]);

        $this->put(route('settings.update'), [...Setting::cached(), 'shop_name' => 'Soran Store'])
            ->assertSessionHasNoErrors();
    }

    /**
     * The lesson from the storage limit, kept.
     *
     * Breeze's login route carries no name, so a name-based allowlist refused
     * it and a shop that hit its limit could not get back in at all. Written
     * with a real POST to the form, because actingAs() is what hid it.
     */
    public function test_an_expired_shop_can_still_sign_in(): void
    {
        $this->admin->forceFill(['password' => 'a-strong-password-2026'])->save();

        $this->licensed(['expires' => now()->subDays(30)->toDateString()]);

        auth()->logout();
        session()->flush();

        $this->post(route('login'), [
            'email' => 'admin@example.com',
            'password' => 'a-strong-password-2026',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    /** And can still set up the thing that gets them back in next time. */
    public function test_an_expired_shop_can_still_manage_its_authenticator(): void
    {
        $this->licensed(['expires' => now()->subDays(30)->toDateString()]);

        $this->get(route('authenticator.show'))->assertOk();

        $this->post(route('authenticator.confirm'), [
            'code' => Totp::at(session('authenticator.secret')),
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->admin->fresh()->hasAuthenticator());
    }

    // =====================================================================
    // Saying so
    // =====================================================================

    public function test_the_banner_arrives_before_the_date(): void
    {
        $this->licensed(['expires' => now()->addDays(5)->toDateString()]);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee(__('After that, nothing new can be saved.'), false);
    }

    public function test_the_banner_says_so_plainly_once_it_has_run_out(): void
    {
        $this->licensed(['expires' => now()->subDays(30)->toDateString()]);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee(__('The licence is not valid. Nothing new can be saved.'), false);
    }

    /** A counter assistant seeing this on every sale learns to stop reading banners. */
    public function test_staff_are_not_shown_a_warning_they_cannot_act_on(): void
    {
        $this->licensed(['expires' => now()->addDays(5)->toDateString()]);

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'sales.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('sales.index'))->assertOk()
            ->assertDontSee(__('After that, nothing new can be saved.'), false);
    }

    public function test_the_settings_card_names_the_shop_and_the_date(): void
    {
        $this->licensed(['shop' => 'Bazaar Electronics', 'expires' => now()->addMonths(3)->toDateString()]);

        $this->get(route('settings.edit'))->assertOk()
            ->assertSee(__('Licence'), false)
            ->assertSee('Bazaar Electronics', false)
            ->assertSee('TEST-0001', false);
    }

    /**
     * The bug the array cache driver hid.
     *
     * check() holds its answer for a minute, and the answer used to include a
     * Carbon object. Tests run on the array driver, which hands objects back
     * exactly as they were; every real install runs on file or database, which
     * serialise — and a Carbon came back from those as an incomplete class and
     * took the settings page down with a 500 on the second request.
     *
     * So this asks the question through a driver that serialises, which is the
     * only kind of driver a shop will ever have.
     */
    public function test_the_held_answer_survives_a_cache_that_serialises(): void
    {
        config(['cache.default' => 'file']);

        $this->licensed(['expires' => now()->addDays(20)->toDateString()]);

        // First read fills the cache; second reads it back through the driver.
        $first = app(Licence::class)->check();
        $second = app(Licence::class)->check();

        $this->assertInstanceOf(Carbon::class, $second['expires']);
        $this->assertSame(
            $first['expires']->toDateString(),
            $second['expires']->toDateString(),
            'the same date came back out',
        );

        // And the page it broke actually renders, twice.
        $this->get(route('settings.edit'))->assertOk();
        $this->get(route('settings.edit'))->assertOk()->assertSee(__('Licensed to'), false);

        app(Licence::class)->forget();
    }

    // =====================================================================
    // The seller's own tools
    // =====================================================================

    public function test_the_console_says_what_this_copy_makes_of_its_licence(): void
    {
        $this->licensed(['shop' => 'Bazaar Electronics']);

        $this->artisan('licence:show')
            ->expectsOutputToContain('Bazaar Electronics')
            ->assertSuccessful();
    }

    /** And exits non-zero when it is not valid, so a script can notice. */
    public function test_the_console_exits_unhappy_when_the_licence_is_not_valid(): void
    {
        $this->licensed(['expires' => now()->subDays(30)->toDateString()]);

        $this->artisan('licence:show')->assertFailed();
    }

    /** Making a second keypair would break every licence already issued. */
    public function test_making_new_keys_is_refused_when_a_key_is_already_in_place(): void
    {
        config(['licence.public_key' => self::$public]);

        $this->artisan('licence:keys')->assertFailed();
    }
}
