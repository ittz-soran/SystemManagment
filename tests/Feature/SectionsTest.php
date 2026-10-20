<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockRoom;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\RepairService;
use App\Support\Guide;
use App\Support\Navigation;
use App\Support\Notifications;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PROJECT_DOC Section 8h — sections a shop can do without.
 *
 * Soran, 2026-10-19: *"real case i have halabja phone and say don't need stok
 * rooms and second hand and repair service, i want manage from panel"*. That
 * shop is Hamza now, and its line is the one most of these tests switch.
 *
 * ⚠️ Two promises everything here is checked against: an off section is not
 * there for anybody, the admin included — and switching it off deletes nothing,
 * so switching it back on brings back exactly what was there.
 */
class SectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function off(string ...$keys): void
    {
        config(['sections.off' => implode(',', $keys)]);
    }

    // ---- the switch itself -------------------------------------------------

    /** With nothing in .env every released section is on — every shop today. */
    public function test_a_shop_with_nothing_set_has_every_section(): void
    {
        $this->assertSame([], Sections::off());
        $this->assertSame([], Sections::unknown());

        foreach (array_keys(Sections::LIST) as $key) {
            $this->assertTrue(Sections::isOn($key), $key);
        }
    }

    public function test_the_line_switches_off_exactly_what_it_names(): void
    {
        config(['sections.off' => ' Stock_Rooms , second_hand,,repairs ']);

        $this->assertSame(['stock_rooms', 'second_hand', 'repairs'], Sections::off());
        $this->assertTrue(Sections::isOn('swaps'));
        $this->assertSame('SHOP_SECTIONS_OFF', Sections::why('repairs'));
        $this->assertSame('default', Sections::why('swaps'));
    }

    /** ⚠️ `repair` for `repairs` switches nothing, so it is reported rather than dropped. */
    public function test_a_name_that_is_no_section_is_reported(): void
    {
        $this->off('repair', 'swaps');

        $this->assertSame(['repair'], Sections::unknown());
        $this->assertTrue(Sections::isOn('repairs'));
        $this->assertFalse(Sections::isOn('swaps'));
    }

    // ---- a page tried on some shops first ----------------------------------

    private function withATestingSection(): void
    {
        config(['sections.list' => [...Sections::LIST, 'instalments' => [
            'name' => 'Instalments',
            'permissions' => 'instalments',
            'paths' => ['instalments', 'instalments/*'],
            'modules' => ['instalments'],
            'testing' => true,
        ]]]);
    }

    public function test_a_section_in_testing_is_off_until_its_shop_asks_for_it(): void
    {
        $this->withATestingSection();

        $this->assertFalse(Sections::isOn('instalments'));
        $this->assertFalse($this->admin->hasPermission('instalments.view'));

        config(['sections.on' => 'instalments']);

        $this->assertTrue(Sections::isOn('instalments'));
        $this->assertSame('SHOP_SECTIONS_ON', Sections::why('instalments'));
        $this->assertTrue($this->admin->hasPermission('instalments.view'));
    }

    /** ⚠️ OFF wins: switching off is the deliberate act. */
    public function test_a_name_in_both_lines_is_off(): void
    {
        $this->withATestingSection();
        config(['sections.on' => 'instalments', 'sections.off' => 'instalments']);

        $this->assertFalse(Sections::isOn('instalments'));
    }

    // ---- not there, for anybody --------------------------------------------

    /** @return array<string, list<string>> */
    private function pagesOf(): array
    {
        return [
            'stock_rooms' => [route('stock-rooms.index'), route('stock-transfers.index')],
            // ⚠️ Opened with purchases.create — only the address can close it.
            'second_hand' => [route('second-hand.index'), route('second-hand.create')],
            'repairs' => [route('repairs.index'), route('repairs.create'), route('reports.technicians')],
            'assemblies' => [route('assemblies.index')],
            'swaps' => [route('swaps.index')],
            // ⚠️ Opened with settings.manage — likewise.
            'currencies' => [route('currencies.index')],
        ];
    }

    public function test_every_section_answers_its_pages_while_on(): void
    {
        foreach ($this->pagesOf() as $key => $pages) {
            foreach ($pages as $page) {
                $this->actingAs($this->admin)->get($page)->assertOk();
            }
        }
    }

    /** ⚠️ The admin too. The section is not there to be allowed into. */
    public function test_an_off_section_is_a_page_that_does_not_exist_even_for_the_admin(): void
    {
        foreach ($this->pagesOf() as $key => $pages) {
            $this->off($key);

            foreach ($pages as $page) {
                $this->actingAs($this->admin)->get($page)->assertNotFound();
            }
        }
    }

    public function test_choosing_a_currency_to_read_in_is_gone_with_currencies(): void
    {
        $this->off('currencies');

        $this->actingAs($this->admin)
            ->post(route('preferences.currency'), ['currency' => 'USD'])
            ->assertNotFound();
    }

    /** `repairs/*` must not catch the FIFO repair, which is stock, not the workshop. */
    public function test_switching_repairs_off_leaves_the_stock_repair_alone(): void
    {
        $this->off('repairs');

        $this->assertNull(Sections::offFor(Request::create('/stock/repair', 'POST')));
    }

    /**
     * ⚠️ **A screen added later cannot slip out of its section.** Every route
     * whose permission belongs wholly to a section must sit inside that
     * section's paths, or switching the section off would leave it answering.
     */
    public function test_every_route_a_section_guards_is_inside_its_paths(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                // A list is OR: Goods coming back opens for swaps OR returns,
                // so it belongs to no one section.
                $groups = array_unique(array_map(
                    fn (string $key) => Str::before($key, '.'),
                    explode(',', Str::after($middleware, 'permission:')),
                ));

                foreach (Sections::LIST as $key => $section) {
                    if ($groups !== [$section['permissions']]) {
                        continue;
                    }

                    $checked++;

                    $this->assertTrue(
                        Request::create('/'.$route->uri())->is(...$section['paths']),
                        "[{$route->uri()}] needs {$middleware} but is not in the paths of [{$key}]",
                    );
                }
            }
        }

        $this->assertGreaterThan(40, $checked, 'The check found almost no routes to check.');
    }

    public function test_the_menu_and_search_leave_it_out(): void
    {
        $this->off('stock_rooms', 'second_hand', 'repairs');

        $routes = array_column(Navigation::pagesFor($this->admin), 'route');

        foreach (['stock-rooms.index', 'stock-transfers.index', 'second-hand.index', 'repairs.index'] as $gone) {
            $this->assertNotContains($gone, $routes);
        }

        // The rest of the shop is all still there.
        foreach (['sales.create', 'products.index', 'services.index', 'swaps.index', 'goods-back.index'] as $kept) {
            $this->assertContains($kept, $routes);
        }

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.route('repairs.index').'"', false)
            ->assertDontSee('href="'.route('stock-rooms.index').'"', false);
    }

    /**
     * ⚠️ The users form previews the menu a person will get, and for an admin
     * it takes every screen on the map. Found in a browser, not by a test.
     */
    public function test_the_users_form_does_not_promise_an_off_section(): void
    {
        $this->actingAs($this->admin)->get(route('users.create'))->assertSee('repairs.view');

        $this->off('repairs');

        $this->actingAs($this->admin)->get(route('users.create'))
            ->assertOk()
            ->assertDontSee('repairs.view')
            ->assertSee('sales.view');
    }

    /** Goods coming back still opens for returns when swaps are off. */
    public function test_the_returns_counter_stays_without_swaps(): void
    {
        $this->off('swaps');

        $this->assertContains('goods-back.index', array_column(Navigation::pagesFor($this->admin), 'route'));
        $this->actingAs($this->admin)->get(route('goods-back.index'))->assertOk();
    }

    public function test_the_guide_has_no_repair_topics_in_a_shop_without_repairs(): void
    {
        $this->off('repairs');

        $this->assertArrayNotHasKey('repair-taking-in', Guide::topics());
        $this->assertArrayNotHasKey('repairs', Guide::groups());
        $this->assertNotContains(__('Repairs, for anything with a fault'), array_column(Guide::whatsNew(), 'title'));

        $this->actingAs($this->admin)->get(route('guide.show', 'repair-taking-in'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('guide.index'))
            ->assertOk()
            ->assertDontSee(__('Somebody brings in a broken device'));
    }

    public function test_a_passage_about_an_off_section_goes_and_its_topic_stays(): void
    {
        $this->off('currencies');

        $headings = array_column(Guide::topics()['recording-purchases']['sections'], 0);

        $this->assertNotContains(__('Buying in dollars'), $headings);
        $this->assertContains(__('Recording what you bought'), array_column(Guide::topics(), 'title'));
    }

    public function test_the_bell_says_nothing_about_an_off_section(): void
    {
        $this->assertContains('currencies', Notifications::modulesFor($this->admin));

        $this->off('currencies', 'repairs');

        $modules = Notifications::modulesFor($this->admin);

        $this->assertNotContains('currencies', $modules);
        $this->assertNotContains('repairs', $modules);
        $this->assertContains('sales', $modules);
    }

    // ---- currencies --------------------------------------------------------

    public function test_without_currencies_the_screens_offer_the_shops_own_money_alone(): void
    {
        $this->assertArrayHasKey('USD', Currency::offered());

        $this->off('currencies');

        $this->assertSame(['IQD'], array_keys(Currency::offered()));

        // The full list is untouched — it is how an old document prints.
        $this->assertArrayHasKey('USD', Currency::cached());
    }

    public function test_a_reader_who_chose_dollars_reads_in_dinars_while_it_is_off(): void
    {
        $this->admin->forceFill(['display_currency' => 'USD'])->save();
        $this->assertSame('USD', $this->admin->fresh()->lens()?->code);

        $this->off('currencies');

        $this->assertNull($this->admin->fresh()->lens());

        // Kept, for the day it comes back.
        $this->assertSame('USD', $this->admin->fresh()->display_currency);
    }

    /** ⚠️ Last month's dollar invoice still says what was typed. */
    public function test_a_dollar_purchase_still_prints_its_dollars(): void
    {
        $purchase = $this->purchaseInDollars();

        $this->off('currencies');

        $this->actingAs($this->admin)->get(route('purchases.show', $purchase))
            ->assertOk()
            ->assertSee('9.93');
    }

    /**
     * ⚠️ Saving Settings must not quietly put purchases back into dinars. The
     * box is not drawn, so nothing is posted, so nothing is written.
     */
    public function test_saving_settings_without_currencies_keeps_what_purchases_are_written_in(): void
    {
        $this->actingAs($this->admin)->get(route('settings.edit'))
            ->assertSee('id="purchase_currency"', false);

        Setting::put('purchase_currency', 'USD');
        $this->off('currencies');

        $html = $this->actingAs($this->admin)->get(route('settings.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="purchase_currency"', $html);
        $this->assertStringNotContainsString(route('currencies.index'), $html);

        $form = $this->settingsForm($html);
        $this->actingAs($this->admin)->put(route('settings.update'), $form)->assertSessionHasNoErrors();

        $this->assertSame('USD', setting('purchase_currency'));
    }

    // ---- stock rooms -------------------------------------------------------

    public function test_without_stock_rooms_a_purchase_has_no_room_box_and_lands_on_the_shop_floor(): void
    {
        $back = StockRoom::create(['name' => 'Back room', 'is_main' => false, 'is_active' => true]);

        $this->actingAs($this->admin)->get(route('purchases.create'))->assertSee('id="room_id"', false);

        $this->off('stock_rooms');

        $this->actingAs($this->admin)->get(route('purchases.create'))->assertDontSee('id="room_id"', false);

        // A stale form still carrying the back room does not get it.
        $product = $this->product();
        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => Supplier::create(['name' => 'Click Gaming'])->id,
            'purchase_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'room_id' => $back->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10_000]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockRoom::main()->id, StockBatch::where('product_id', $product->id)->sole()->room_id);
    }

    // ---- what the users had ------------------------------------------------

    /**
     * ⚠️ The editor saves with sync(), which removes every key it is not sent.
     * Saving somebody while repairs are off must not take their repair keys.
     */
    public function test_saving_a_user_while_a_section_is_off_keeps_their_keys_for_it(): void
    {
        $bench = User::create([
            'name' => 'Bench', 'email' => 'bench@example.com', 'password' => 'secret-secret-1',
            'role' => User::ROLE_USER, 'is_active' => true,
        ]);
        $bench->permissions()->attach(Permission::whereIn('key', ['auth.login', 'sales.view', 'repairs.view', 'repairs.edit'])->pluck('id'));

        $this->off('repairs');

        $edit = $this->actingAs($this->admin)->get(route('users.edit', $bench))->assertOk();
        $edit->assertDontSee('value="'.Permission::where('key', 'repairs.view')->value('id').'"', false);

        $this->actingAs($this->admin)->put(route('users.update', $bench), [
            'name' => 'Bench', 'email' => 'bench@example.com', 'role' => User::ROLE_USER,
            'cost_visibility' => User::COST_REAL, 'is_active' => 1,
            'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
            'permissions' => Permission::whereIn('key', ['auth.login', 'sales.view', 'sales.create'])->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        $keys = $bench->fresh()->permissions()->pluck('key')->all();

        $this->assertContains('sales.create', $keys);
        $this->assertContains('repairs.view', $keys);
        $this->assertContains('repairs.edit', $keys);
        $this->assertFalse($bench->fresh()->hasPermission('repairs.view'));

        // And on again, exactly as they were.
        $this->off();
        $this->assertTrue($bench->fresh()->hasPermission('repairs.edit'));
    }

    // ---- what stops a section going ----------------------------------------

    public function test_stock_in_a_back_room_holds_stock_rooms(): void
    {
        $this->assertNull(Sections::holds('stock_rooms'));

        $back = StockRoom::create(['name' => 'Back room', 'is_main' => false, 'is_active' => true]);
        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Click Gaming']),
            lines: [['product_id' => $this->product()->id, 'quantity' => 14, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), roomId: $back->id,
        );

        $this->assertSame('14 units are in Back room. Move them to the main room first.', Sections::holds('stock_rooms'));
    }

    public function test_an_open_repair_job_holds_repairs(): void
    {
        $this->assertNull(Sections::holds('repairs'));

        foreach ([1, 2] as $n) {
            app(RepairService::class)->create(
                customer: Customer::create(['name' => 'Customer '.$n]),
                device: 'iPhone 12 Pro', fault: 'Screen cracked', user: $this->admin,
            );
        }

        $this->assertSame('2 repair jobs are open. A customer\'s device is in the shop.', Sections::holds('repairs'));
    }

    // ---- the shop's own answer, for the panel ------------------------------

    public function test_shop_sections_answers_the_panel_in_json(): void
    {
        $this->off('stock_rooms', 'second_hand', 'repairs', 'repair');

        $this->artisan('shop:sections', ['--json' => true])->assertSuccessful();

        $report = Sections::report();

        $this->assertTrue($report['holds_checked']);
        $this->assertSame(['repair'], $report['unknown']);
        $this->assertSame(
            ['stock_rooms' => false, 'second_hand' => false, 'repairs' => false, 'assemblies' => true, 'swaps' => true, 'currencies' => true],
            array_column($report['sections'], 'on', 'key'),
        );
    }

    /** In prose an unknown name is a failure somebody has to fix. */
    public function test_shop_sections_fails_in_prose_on_a_name_it_does_not_know(): void
    {
        $this->artisan('shop:sections')->assertSuccessful();

        $this->off('repair');

        $this->artisan('shop:sections')->assertFailed();
    }

    // ---- nothing in the books moves ----------------------------------------

    /** Every report and check still opens with all six off. */
    public function test_the_reports_and_checks_open_with_every_section_off(): void
    {
        $this->purchaseInDollars();
        $this->off(...array_keys(Sections::LIST));

        foreach ([route('reports.index'), route('settings.data-check'), route('dashboard'), route('products.index')] as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    /** Used items still sell, so their count stays — it just stops being a link. */
    public function test_a_categorys_second_hand_count_stays_without_its_link(): void
    {
        $category = Category::create(['name' => 'Phones']);
        Product::create([
            'name' => 'Used iPhone 11', 'sku' => 'U1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 200_000, 'sale_price' => 260_000, 'quantity' => 0, 'kind' => Product::KIND_USED,
        ]);

        $this->off('second_hand');

        $this->actingAs($this->admin)->get(route('categories.index'))
            ->assertOk()
            ->assertSee(trans_choice('{1}:count second-hand item|[2,*]:count second-hand items', 1, ['count' => 1]))
            ->assertDontSee('href="'.route('second-hand.index').'"', false);
    }

    // ---- helpers -------------------------------------------------------------

    private function product(): Product
    {
        return Product::create([
            'name' => 'Power Supply 650W', 'sku' => 'PSU'.uniqid(), 'category_id' => Category::firstOrFail()->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);
    }

    private function purchaseInDollars(): Purchase
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => Supplier::create(['name' => 'Erbil Electronics'])->id,
            'purchase_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'exchange_rate' => 1_550,
            'lines' => [[
                'product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 15_392,
                'entered_currency' => 'USD', 'entered_amount' => 993,
            ]],
        ])->assertSessionHasNoErrors();

        return Purchase::latest('id')->firstOrFail();
    }

    /**
     * What the settings form posts, read off the page as a browser would —
     * every field it draws and none it does not.
     *
     * @return array<string, mixed>
     */
    private function settingsForm(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $form = $xpath->query('//form[.//input[@name="shop_name"]]')->item(0);
        $this->assertNotNull($form, 'The settings form was not found.');

        $data = [];

        foreach ($xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $form) as $field) {
            $name = $field->getAttribute('name');
            $type = strtolower($field->getAttribute('type'));

            if (in_array($type, ['file', 'submit', 'button'], true)) {
                continue;
            }

            if (in_array($type, ['checkbox', 'radio'], true) && ! $field->hasAttribute('checked')) {
                continue;
            }

            $value = match ($field->nodeName) {
                'select' => ($xpath->query('.//option[@selected]', $field)->item(0)
                    ?? $xpath->query('.//option', $field)->item(0))?->getAttribute('value') ?? '',
                'textarea' => $field->textContent,
                default => $field->getAttribute('value'),
            };

            if (str_ends_with($name, '[]')) {
                $data[substr($name, 0, -2)][] = $value;
            } elseif (preg_match('/^([^\[]+)\[([^\]]*)\]$/', $name, $m)) {
                $data[$m[1]][$m[2]] = $value;
            } else {
                $data[$name] = $value;
            }
        }

        unset($data['_method']);

        return $data;
    }
}
