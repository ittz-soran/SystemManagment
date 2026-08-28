<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What a member of staff can reach, and what they can see once they are there.
 *
 * The shop is hosted now, which changes who is on the other side of these
 * checks. Every case here is something that was actually open: a permission
 * that handed over the whole system, and a dashboard that told a salesperson
 * what the shop spends and what its shelves cost.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Section 9: "Users — CRUD, admin only."
     *
     * The screen was behind `permission:users.view`, which made that key the
     * only one anybody needed: the form it opens saves a role, and the role it
     * saves can be admin.
     */
    public function test_a_permission_cannot_open_the_users_screens(): void
    {
        $staff = $this->staffWith(['users.view', 'users.create', 'users.edit', 'users.delete']);

        $this->actingAs($staff)->get(route('users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('users.create'))->assertForbidden();
        $this->actingAs($staff)->get(route('users.edit', $staff))->assertForbidden();

        $this->actingAs($staff)->post(route('users.store'), [
            'name' => 'Back Door',
            'email' => 'backdoor@example.com',
            'password' => 'a-strong-password-2026',
            'password_confirmation' => 'a-strong-password-2026',
            'role' => User::ROLE_ADMIN,
            'is_active' => 1,
            'language' => 'en',
            'theme' => 'auto',
            'items_per_page' => 25,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'backdoor@example.com']);
    }

    /** Section 9b: never show a link that leads to "access denied". */
    public function test_neither_the_sidebar_nor_the_search_box_offers_users_to_staff(): void
    {
        $staff = $this->staffWith(['dashboard.view', 'users.view']);

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('users.index'));

        $this->actingAs($staff)->get(route('search', ['q' => 'user']))
            ->assertOk()
            ->assertJsonMissing(['url' => route('users.index')]);
    }

    /** The checkbox would either do nothing or hand over the shop. */
    public function test_the_permission_editor_does_not_offer_the_users_keys(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->get(route('users.create'))
            ->assertOk()
            ->assertSee('View products')
            ->assertDontSee('View users')
            ->assertDontSee('Delete users');
    }

    /** The shop has to keep a way back in. */
    public function test_an_admin_cannot_take_away_their_own_admin_access(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => User::ROLE_USER,
            'is_active' => 1,
            'language' => 'en',
            'theme' => 'auto',
            'items_per_page' => 25,
        ])->assertRedirect();

        $this->assertTrue($admin->refresh()->isAdmin());
    }

    /**
     * The reported bug: an account with the sale screens saw the shop's whole
     * financial position on the way in.
     */
    public function test_the_dashboard_shows_only_the_panels_the_reader_may_open(): void
    {
        $seller = $this->staffWith(['dashboard.view', 'sales.create', 'sales.view']);

        $response = $this->actingAs($seller)->get(route('dashboard'))->assertOk();

        $response->assertSee("Today's sales")
            ->assertSee('Recent sales');

        // The tiles stay. A missing one says the shop has no such figure; a
        // masked one says there is one and it is not theirs.
        foreach ([
            "Today's purchases",
            "Today's expenses",
            'Stock value',
            'Customers owe the shop',
            'The shop owes suppliers',
        ] as $shown) {
            $response->assertSee($shown);
        }

        // Four masked figures: purchases, expenses, stock value, and the two
        // balances — the sales figure is the only one they may have.
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($response->getContent(), hidden_money()),
            'Every figure this reader may not see has to be masked',
        );

        // The lists are screens rather than figures, and stay behind their own
        // permission — there is nothing to mask in a table of somebody's rows.
        $response->assertDontSee('Low stock');
    }

    /** And the buttons beside a masked figure do not lead anywhere they cannot go. */
    public function test_a_masked_balance_offers_no_way_into_the_screen_behind_it(): void
    {
        $seller = $this->staffWith(['dashboard.view', 'sales.view']);

        $this->actingAs($seller)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('customers.index'))
            ->assertDontSee(route('suppliers.index'));
    }

    public function test_the_dashboard_shows_everything_to_an_admin(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        foreach ([
            "Today's sales",
            "Today's purchases",
            "Today's expenses",
            'Stock value',
            'Customers owe the shop',
            'The shop owes suppliers',
            'Low stock',
            'Recent sales',
        ] as $shown) {
            $response->assertSee($shown);
        }
    }

    /**
     * What the shelf cost, and what the shop would make on it, are `reports.view`
     * figures. What is in stock and what it sells for are not.
     */
    public function test_the_products_list_masks_what_the_shelf_cost(): void
    {
        $seller = $this->staffWith(['products.view']);

        // The tiles stay; the figures do not.
        $this->actingAs($seller)->get(route('products.index'))
            ->assertOk()
            ->assertSee('Low stock')
            ->assertSee('what the unsold batches cost')
            ->assertSee('At sale price')
            ->assertSee(hidden_money().' '.__('IQD'));

        $manager = $this->staffWith(['products.view', 'reports.view'], 'manager@example.com');

        $this->actingAs($manager)->get(route('products.index'))
            ->assertOk()
            ->assertSee('what the unsold batches cost')
            ->assertDontSee(hidden_money());
    }

    /** Nothing on these pages comes from anywhere but the shop's own address. */
    public function test_every_page_carries_the_security_headers(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        foreach ([$this->get('/login'), $this->actingAs($admin)->get(route('dashboard'))] as $response) {
            $response->assertOk();

            $csp = $response->headers->get('Content-Security-Policy');

            $this->assertStringContainsString("default-src 'self'", (string) $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", (string) $csp);
            $this->assertStringContainsString("object-src 'none'", (string) $csp);

            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
            $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        }
    }

    /**
     * The rows those screens draw from JSON are built as HTML strings, so the
     * escaper has to be on the page before any of them runs — including the
     * login screen, which loads the same bundle.
     */
    public function test_the_escaper_reaches_every_layout(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->get('/login')->assertSee('window.escapeHtml', false);
        $this->actingAs($admin)->get(route('dashboard'))->assertSee('window.escapeHtml', false);
    }

    /**
     * Every copy of this system used to install with the same administrator
     * password. Reachable from the internet, that is the whole shop.
     */
    public function test_the_install_does_not_ship_a_known_admin_password(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        foreach (['password', 'admin', '12345678', 'admin@example.com'] as $guess) {
            $this->assertFalse(
                Hash::check($guess, $admin->password),
                "The seeded administrator must not be reachable with \"{$guess}\"",
            );
        }

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Three screens used to ride on products.view, so anybody allowed to look
     * at the catalogue was also shown Second-hand, Services, and the screen
     * that hands the shop's customer list to a spreadsheet — and there was no
     * key on the permissions page to withhold any of them.
     */
    public function test_the_catalogue_permission_no_longer_opens_three_other_screens(): void
    {
        $staff = $this->staffWith(['dashboard.view', 'products.view']);

        foreach (['second-hand.index', 'services.index', 'data.index'] as $route) {
            $this->actingAs($staff)->get(route($route))->assertForbidden();
        }

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('second-hand.index'))
            ->assertDontSee(route('services.index'))
            ->assertDontSee(route('data.index'));
    }

    public function test_each_of_those_screens_has_a_key_that_opens_it(): void
    {
        $staff = $this->staffWith([
            'dashboard.view', 'products.view',
            'second_hand.view', 'services.view', 'data.manage',
        ]);

        foreach (['second-hand.index', 'services.index', 'data.index'] as $route) {
            $this->actingAs($staff)->get(route($route))->assertOk();
        }

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('second-hand.index'))
            ->assertSee(route('services.index'))
            ->assertSee(route('data.index'));
    }

    /**
     * Opening the import screen is not the same as being allowed to overwrite
     * the catalogue with it — the per-entity checks inside are still there.
     */
    public function test_the_import_key_is_not_a_way_round_the_others(): void
    {
        $staff = $this->staffWith(['data.manage', 'products.view']);

        $this->actingAs($staff)->get(route('data.index'))->assertOk();
        $this->actingAs($staff)->post(route('data.import', 'products'), ['token' => 'x'])->assertForbidden();
        $this->actingAs($staff)->get(route('data.export', 'customers'))->assertForbidden();
    }

    /** @param  list<string>  $keys */
    private function staffWith(array $keys, string $email = 'assistant@example.com'): User
    {
        $user = User::create([
            'name' => 'Shop Assistant',
            'email' => $email,
            'password' => 'a-strong-password-2026',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'language' => 'en',
            'theme' => 'auto',
            'items_per_page' => 25,
        ]);

        $user->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id')->all());

        return $user;
    }
}
