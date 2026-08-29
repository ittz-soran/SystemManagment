<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\StorageQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A shop sold on a plan with a fixed amount of space.
 *
 * The block at 100% is the most dangerous thing in this system — a till that
 * will not record a sale is worse for the shopkeeper than a full disk, and it
 * is the seller they telephone about it. So most of this is not about the block
 * working. It is about what stays possible while it holds: reading, printing,
 * deleting, and reaching the one screen that explains it.
 *
 * And about the other direction entirely: an install with no plan must show no
 * meter, no banner, and refuse nothing.
 */
class StorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin);

        $this->category = Category::create(['name' => 'Accessories']);
    }

    /** Pretend the shop is using this much, without writing gigabytes to prove it. */
    private function using(int $bytes): void
    {
        Cache::put('storage_quota.used', [
            'database' => $bytes, 'backups' => 0, 'uploads' => 0, 'total' => $bytes,
        ], 600);
    }

    private function limit(?int $mb): void
    {
        config(['quota.limit_mb' => $mb]);
    }

    // =====================================================================
    // An install with no plan carries none of a plan's furniture
    // =====================================================================

    public function test_without_a_limit_there_is_no_meter_and_nothing_is_refused(): void
    {
        $this->limit(null);

        $quota = app(StorageQuota::class);

        $this->assertFalse($quota->isLimited());
        $this->assertNull($quota->limitBytes());
        $this->assertSame(StorageQuota::OK, $quota->state());
        $this->assertFalse($quota->isFull());

        $this->get(route('settings.edit'))->assertOk()->assertDontSee(__('Storage'), false);

        $this->post(route('categories.store'), ['name' => 'Cables'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['name' => 'Cables']);
    }

    // =====================================================================
    // The figure
    // =====================================================================

    public function test_it_reports_what_is_used_and_what_is_left(): void
    {
        $this->limit(100);            // 100 MB
        $this->using(25 * 1024 * 1024); // 25 MB

        $quota = app(StorageQuota::class);

        $this->assertSame(25.0, $quota->percentUsed());
        $this->assertSame(75 * 1024 * 1024, $quota->remainingBytes());
        $this->assertSame(StorageQuota::OK, $quota->state());
    }

    public function test_the_states_arrive_in_the_right_order(): void
    {
        $this->limit(100);

        foreach ([
            10 => StorageQuota::OK,
            79 => StorageQuota::OK,
            80 => StorageQuota::WARNING,
            94 => StorageQuota::WARNING,
            95 => StorageQuota::CRITICAL,
            99 => StorageQuota::CRITICAL,
            100 => StorageQuota::FULL,
            140 => StorageQuota::FULL,
        ] as $mb => $expected) {
            $this->using($mb * 1024 * 1024);

            $this->assertSame($expected, app(StorageQuota::class)->state(), "at {$mb} MB");
        }
    }

    /** A shop over its plan has no room left, not negative room. */
    public function test_being_over_the_plan_does_not_read_as_a_negative(): void
    {
        $this->limit(100);
        $this->using(180 * 1024 * 1024);

        $quota = app(StorageQuota::class);

        $this->assertSame(0, $quota->remainingBytes());
        $this->assertSame(100.0, $quota->percentUsed(), 'a bar cannot draw past its own box');
    }

    /** The real measurement, against the real database. */
    public function test_it_actually_measures_something(): void
    {
        $this->limit(1000);

        $breakdown = app(StorageQuota::class)->breakdown();

        $this->assertArrayHasKey('database', $breakdown);
        $this->assertArrayHasKey('backups', $breakdown);
        $this->assertArrayHasKey('uploads', $breakdown);
        $this->assertSame(
            $breakdown['database'] + $breakdown['backups'] + $breakdown['uploads'],
            $breakdown['total'],
        );
    }

    // =====================================================================
    // Full: what stops, and what must not
    // =====================================================================

    public function test_when_full_nothing_new_can_be_saved(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        $this->post(route('categories.store'), ['name' => 'Cables'])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('categories', ['name' => 'Cables']);
    }

    public function test_when_full_an_edit_is_refused_too(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        $this->put(route('categories.update', $this->category), ['name' => 'Renamed'])
            ->assertSessionHas('error');

        $this->assertSame('Accessories', $this->category->fresh()->name);
    }

    /** Reading is how a shop keeps trading on what it already has. */
    public function test_when_full_every_screen_still_opens(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        foreach ([
            route('dashboard'), route('products.index'), route('sales.index'),
            route('customers.index'), route('reports.index'), route('settings.edit'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /**
     * The door has to open from the inside.
     *
     * Space is freed by deleting, so a block that also blocked deleting would
     * leave the shop with no way out of the state it had been put in.
     */
    public function test_when_full_deleting_still_works(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        $spare = Category::create(['name' => 'Spare']);

        $this->delete(route('categories.destroy', $spare))->assertSessionHasNoErrors();

        $this->assertSoftDeleted('categories', ['id' => $spare->id]);
    }

    /** A form spoofing DELETE through a POST is still a delete. */
    public function test_a_spoofed_delete_is_treated_as_a_delete(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        $spare = Category::create(['name' => 'Spare']);

        $this->post(route('categories.destroy', $spare), ['_method' => 'DELETE'])
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('categories', ['id' => $spare->id]);
    }

    /**
     * Settings is the one lever inside the system that frees space — shortening
     * how many backups are kept — so it cannot be behind the door it opens.
     */
    public function test_when_full_settings_can_still_be_saved(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        // The whole form as it stands, with the one field changed — which is
        // what the screen actually posts, and the field that frees space.
        $this->put(route('settings.update'), [
            ...Setting::cached(),
            'backup_keep_daily' => 7,
        ])->assertSessionHasNoErrors();

        $this->assertSame('7', (string) setting('backup_keep_daily'));
    }

    /** Being unable to leave a screen that refuses to work is its own trap. */
    public function test_when_full_signing_out_still_works(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    /** What the assistant typed is still in the boxes when the refusal comes back. */
    public function test_a_refused_sale_does_not_lose_what_was_typed(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        $product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $this->category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000,
            'quantity' => 0, 'is_active' => true,
        ]);

        $this->post(route('sales.store'), [
            'customer_id' => Customer::first()->id,
            'sale_date' => today()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 15_000]],
            'amount_paid' => 15_000,
        ])->assertSessionHas('error')->assertSessionHasInput('amount_paid', 15_000);
    }

    /**
     * The one that nearly shipped: a full shop could not sign in.
     *
     * Logging in is a POST, and Breeze's login route has no name — so an
     * allowlist that reads route names refused it, and a shop that filled its
     * plan was locked out of its own records. The owner too, which meant nobody
     * could reach the screen explaining it or free a single byte.
     *
     * Written with an actual POST to the login form rather than actingAs(),
     * because actingAs() is exactly what hid it.
     */
    public function test_a_full_shop_can_still_sign_in(): void
    {
        // The seeded password is generated at install, so give it a known one
        // first — this is about the door, not about the key.
        $this->admin->forceFill(['password' => 'a-strong-password-2026'])->save();

        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        auth()->logout();
        session()->flush();

        $this->post(route('login'), [
            'email' => 'admin@example.com',
            'password' => 'a-strong-password-2026',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    /** And the rest of the way back in: a forgotten password still works too. */
    public function test_a_full_shop_can_still_ask_for_a_password_reset(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        auth()->logout();
        session()->flush();

        $this->post(route('password.email'), ['email' => 'admin@example.com'])
            ->assertSessionHasNoErrors();
    }

    /** Language and theme are the reader's own, and cost the plan nothing. */
    public function test_when_full_the_language_can_still_be_changed(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        $this->post(route('preferences.language'), ['language' => 'ckb'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ckb', $this->admin->fresh()->language);
    }

    // =====================================================================
    // Saying so, before and while
    // =====================================================================

    public function test_the_banner_arrives_before_the_door_closes(): void
    {
        $this->limit(100);
        $this->using(85 * 1024 * 1024);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('At the end of it, nothing new can be saved.'), false);
    }

    public function test_the_banner_says_so_plainly_once_it_is_full(): void
    {
        $this->limit(100);
        $this->using(100 * 1024 * 1024);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('Storage is full. Nothing new can be saved.'), false);
    }

    /** A counter assistant seeing this on every sale learns to stop reading banners. */
    public function test_staff_are_not_shown_a_warning_they_cannot_act_on(): void
    {
        $this->limit(100);
        $this->using(85 * 1024 * 1024);

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::whereIn('key', ['sales.view', 'sales.create'])->pluck('id')->all());

        $this->actingAs($staff)->get(route('sales.index'))
            ->assertOk()
            ->assertDontSee(__('At the end of it, nothing new can be saved.'), false);
    }

    /** But they are told why, at the moment it stops them. */
    public function test_staff_are_told_why_when_it_refuses_them(): void
    {
        $this->limit(10);
        $this->using(10 * 1024 * 1024);

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant2@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'categories.create')->pluck('id')->all());

        $this->actingAs($staff)
            ->post(route('categories.store'), ['name' => 'Cables'])
            ->assertSessionHas('error', __('There is no storage left, so nothing new can be saved. Ask the shop owner — reading and printing still work in the meantime.'));
    }

    public function test_the_meter_shows_where_the_space_went(): void
    {
        $this->limit(100);
        $this->using(40 * 1024 * 1024);

        $this->get(route('settings.edit'))
            ->assertOk()
            ->assertSee(__('Storage'), false)
            ->assertSee(__('The shop’s records'), false)
            ->assertSee(__('Backups kept'), false)
            ->assertSee(__('Uploaded files'), false);
    }

    /** The buyer may read the meter. They may not raise it. */
    public function test_the_limit_cannot_be_changed_from_the_settings_screen(): void
    {
        $this->limit(10);
        $this->using(2 * 1024 * 1024);

        $this->put(route('settings.update'), [
            ...Setting::cached(),
            'storage_limit_mb' => 999999,
            'quota_limit_mb' => 999999,
        ]);

        $this->assertSame(10 * 1024 * 1024, app(StorageQuota::class)->limitBytes());
        $this->assertDatabaseMissing('settings', ['key' => 'storage_limit_mb']);
        $this->assertDatabaseMissing('settings', ['key' => 'quota_limit_mb']);
    }

    // =====================================================================
    // Whether the screen can still reach the server
    // =====================================================================

    /**
     * The indicator is on every page, and it is quiet until it is not.
     *
     * What it does once the connection drops is the browser's job and app.js's,
     * which no PHP test can watch. What this can hold is the contract between
     * them: the element is there, it knows what to ask and what to say, and it
     * ships with no words showing.
     */
    public function test_every_page_carries_the_connection_indicator(): void
    {
        $page = $this->get(route('dashboard'))->assertOk();

        $page->assertSee('id="app-connection"', false)
            ->assertSee('data-url="'.url('up').'"', false)
            ->assertSee(__('No connection'), false)
            ->assertSee(__('No connection to the shop’s server. Nothing typed now will be saved — wait for this to clear before ringing up a sale.'), false);
    }

    /** The endpoint it asks has to actually be there. */
    public function test_the_endpoint_it_asks_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    /** A backup moves the figure sharply, so the held number goes with it. */
    public function test_a_backup_makes_the_meter_measure_again(): void
    {
        $this->limit(100);
        $this->using(40 * 1024 * 1024);

        $this->assertSame(40 * 1024 * 1024, app(StorageQuota::class)->usedBytes());

        app(StorageQuota::class)->forget();

        $this->assertNotSame(
            40 * 1024 * 1024,
            app(StorageQuota::class)->usedBytes(),
            'it measured again rather than repeating the held number',
        );
    }
}
