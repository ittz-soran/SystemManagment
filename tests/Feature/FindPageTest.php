<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\Digits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One box, and everything the shop knows about the answer — Soran, 2026-09-24.
 *
 * *"for example I searched PD-17-UK auto show invoices, purchases, statistics,
 * best supplier buy from and customer, and actions like sale or purchase or
 * return"*.
 *
 * ⚠️ **These tests RENDER the page**, which is the only thing that catches a
 * Blade comment naming the `@@php` directive: it pairs with the real
 * `@@endphp` below it and silently deletes everything between, and the page
 * still answers 200.
 */
class FindPageTest extends TestCase
{
    use RefreshDatabase;

    private Product $pd;

    private Supplier $bazaar;

    private Supplier $sulaimani;

    private Customer $karwan;

    private Customer $dana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->pd = Product::create([
            'name' => 'Power bank 17000mAh UK', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-17-UK', 'barcode' => '6972496470268',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 40_000, 'sale_price' => 60_000, 'quantity' => 0,
        ]);

        $this->bazaar = Supplier::create(['name' => 'Bazaar Mobile', 'phone' => '07701112233', 'address' => 'Sulaymaniyah bazaar', 'is_active' => true]);
        $this->sulaimani = Supplier::create(['name' => 'Sulaimani Traders', 'phone' => '07712223344', 'is_active' => true]);
        $this->karwan = Customer::create(['name' => 'Karwan Ahmed', 'phone' => '07501112233']);
        $this->dana = Customer::create(['name' => 'Dana Salih', 'phone' => '07502223344']);
    }

    private function user(): User
    {
        return User::first();
    }

    private function buy(Supplier $from, int $quantity, int $cost = 40_000, int $daysAgo = 30): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $from,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => $cost]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: $quantity * $cost,
        );
    }

    private function sell(Customer $to, int $quantity, int $price = 60_000, int $daysAgo = 5): Sale
    {
        return Sale::find(app(SaleService::class)->create(
            customer: $to,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => $price]],
            user: $this->user(), saleDate: now()->subDays($daysAgo),
            amountPaid: $quantity * $price, paymentMethod: 'cash',
        )->id);
    }

    // ---- The box ---------------------------------------------------------

    public function test_the_empty_page_says_what_it_understands(): void
    {
        $this->actingAs($this->user())
            ->get(route('find'))
            ->assertOk()
            ->assertSee(__('Name, code, barcode, phone, address or a document number'))
            ->assertSee(__('Scan a barcode, type part of a name, a phone number, or the number printed on any document. Kurdish, Arabic and Persian digits are read as English ones.'));
    }

    public function test_a_term_that_matches_nothing_says_so(): void
    {
        $this->actingAs($this->user())
            ->get(route('find', ['q' => 'ZZZ-NOTHING']))
            ->assertOk()
            ->assertSee(__('Nothing matches :term.', ['term' => 'ZZZ-NOTHING']));
    }

    // ---- The dossier -----------------------------------------------------

    /** ⚠️ The whole ask, on one page, from one code. */
    public function test_a_code_shows_the_statistics_the_people_and_the_documents(): void
    {
        $purchase = $this->buy($this->bazaar, 10, 40_000, daysAgo: 60);
        $this->buy($this->sulaimani, 2, 44_000, daysAgo: 30);
        $sale = $this->sell($this->karwan, 3);
        $this->sell($this->dana, 1);

        $page = $this->actingAs($this->user())->get(route('find', ['q' => 'PD-17-UK']));

        $page->assertOk()
            // The product itself, resolved without a second click.
            ->assertSee('Power bank 17000mAh UK')
            ->assertSee(__('What it has done for the shop'))
            // 4 sold at 60,000.
            ->assertSee(money(240_000, false))
            // Cost: three from the 40,000 layer and one more from it too.
            ->assertSee(money(160_000, false))
            // Spend: 10 × 40,000 + 2 × 44,000.
            ->assertSee(money(488_000, false))
            // The two people.
            ->assertSee(__('You buy it from'))
            ->assertSee('Bazaar Mobile')
            ->assertSee(__('Your best customer for it'))
            ->assertSee('Karwan Ahmed')
            // And the documents on both sides.
            ->assertSee($sale->document_no)
            ->assertSee($purchase->document_no);
    }

    /**
     * ⚠️ Units, not money. The supplier worth knowing is the one who keeps the
     * shelf full — one expensive order must not beat a year of steady ones.
     */
    public function test_the_best_supplier_is_the_one_who_supplies_most_not_the_dearest(): void
    {
        $this->buy($this->bazaar, 10, 10_000, daysAgo: 60);
        $this->buy($this->sulaimani, 1, 500_000, daysAgo: 30);

        $page = $this->actingAs($this->user())->get(route('find', ['q' => 'PD-17-UK']));

        $page->assertOk()->assertSee('Bazaar Mobile');

        $best = $page->viewData('bestSupplier');
        $this->assertSame('Bazaar Mobile', $best->name);
        $this->assertSame(10, (int) $best->units);
    }

    /** The faulty tab Soran asked for, on the line itself. */
    public function test_each_invoice_line_offers_the_swap_page_on_that_line(): void
    {
        $this->buy($this->bazaar, 5);
        $sale = $this->sell($this->karwan, 1);

        $this->actingAs($this->user())
            ->get(route('find', ['q' => 'PD-17-UK']))
            ->assertOk()
            ->assertSee(__('Came back faulty'))
            ->assertSee(route('swaps.create', ['sale_item' => $sale->items->first()->id]), false);
    }

    /** A scanned barcode is one product, so the page does not ask again. */
    public function test_a_barcode_resolves_straight_to_the_product(): void
    {
        $this->buy($this->bazaar, 2);

        $this->actingAs($this->user())
            ->get(route('find', ['q' => '6972496470268']))
            ->assertOk()
            ->assertSee(__('What it has done for the shop'))
            ->assertDontSee(__('This one'));
    }

    /** Two matches is a question, not an answer. */
    public function test_two_matches_are_offered_as_a_choice(): void
    {
        Product::create([
            'name' => 'Power bank 10000mAh', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-10', 'barcode' => 'PD-10-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 20_000, 'sale_price' => 30_000, 'quantity' => 0,
        ]);

        $this->actingAs($this->user())
            ->get(route('find', ['q' => 'Power bank']))
            ->assertOk()
            ->assertSee(__('This one'))
            ->assertDontSee(__('What it has done for the shop'))
            ->assertSee('Power bank 10000mAh')
            ->assertSee('Power bank 17000mAh UK');
    }

    /**
     * ⚠️ Three of this shop's four languages are right to left, and a bare
     * 2026-09-04 dropped into RTL text is reordered by the bidi algorithm into
     * 04-09-2026 — correct characters, wrong date. `.app-code` is LTR inside
     * and one box from the outside. Found by rendering the page in Kurdish and
     * looking at it, which is the only way this is ever found.
     */
    public function test_the_last_dealt_date_sits_in_a_code_box(): void
    {
        $this->buy($this->bazaar, 5, 40_000, daysAgo: 30);
        $this->sell($this->karwan, 1, 60_000, daysAgo: 10);

        $html = $this->actingAs($this->user())
            ->get(route('find', ['q' => 'PD-17-UK']))
            ->assertOk()
            ->getContent();

        /*
         * ⚠️ Anchored to the label. The plain string is on this page anyway —
         * every row of both tables prints a date that way — so an assertion
         * that only looked for it passed with these two cards printing their
         * dates bare. Proven by sabotage, after that first version walked
         * through one.
         */
        foreach ([now()->subDays(30), now()->subDays(10)] as $date) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote(__('Last on'), '/').'\s*<span class="app-code">'
                    .preg_quote($date->format(setting('date_format', 'Y-m-d')), '/').'<\/span>/',
                $html,
                'a date was printed loose in a sentence, where RTL will reverse it',
            );
        }
    }

    // ---- The other things the box understands -----------------------------

    public function test_it_finds_people_by_phone_and_by_address(): void
    {
        $byPhone = $this->actingAs($this->user())->get(route('find', ['q' => '07501112233']));
        $byPhone->assertOk()->assertSee('Karwan Ahmed');

        $byAddress = $this->actingAs($this->user())->get(route('find', ['q' => 'bazaar']));
        $byAddress->assertOk()->assertSee('Bazaar Mobile');
    }

    /**
     * ⚠️ Nobody types the zeros. `INV-5` is a LIKE that matches nothing against
     * `INV-00005`, which reads as "the system has lost my invoice".
     */
    public function test_a_document_number_without_its_padding_still_finds_it(): void
    {
        $this->buy($this->bazaar, 2);
        $sale = $this->sell($this->karwan, 1);

        $this->assertSame('INV-00001', $sale->document_no);

        $this->actingAs($this->user())
            ->get(route('find', ['q' => 'INV-1']))
            ->assertOk()
            ->assertSee('INV-00001')
            ->assertSee(route('sales.show', $sale), false);
    }

    /** ⚠️ A keyboard left on Kurdish types INV-٠٠٠٠١, and every LIKE misses it. */
    public function test_eastern_digits_are_read_as_english_ones(): void
    {
        $this->buy($this->bazaar, 2);
        $sale = $this->sell($this->karwan, 1);

        $this->actingAs($this->user())
            ->get(route('find', ['q' => 'INV-٠٠٠٠١']))
            ->assertOk()
            ->assertSee($sale->document_no)
            ->assertSee(route('sales.show', $sale), false);

        // And a phone number typed the same way.
        $this->actingAs($this->user())
            ->get(route('find', ['q' => '۰۷۵۰۱۱۱۲۲۳۳']))
            ->assertOk()
            ->assertSee('Karwan Ahmed');
    }

    public function test_the_digit_reader_handles_both_alphabets_and_the_marks(): void
    {
        $this->assertSame('4500', Digits::english('٤٥٠٠'));
        $this->assertSame('4500', Digits::english('۴۵۰۰'));
        $this->assertSame('4.5', Digits::english('٤٫٥'));
        $this->assertSame('1000', Digits::english('١٬٠٠٠'));
        // Letters are left exactly as they are.
        $this->assertSame('INV-00005', Digits::english('INV-٠٠٠٠٥'));
        $this->assertSame('کاڵا', Digits::english('کاڵا'));
    }

    /**
     * ⚠️ The sale price is matched and the purchase price is not. A box that
     * answers "which of these did I pay 40,000 for" has handed the cost to
     * anybody who can type a number.
     */
    public function test_a_price_finds_what_it_sells_for_never_what_it_cost(): void
    {
        $this->actingAs($this->user())
            ->get(route('find', ['q' => '60000']))
            ->assertOk()
            ->assertSee('Power bank 17000mAh UK');

        $this->actingAs($this->user())
            ->get(route('find', ['q' => '40000']))
            ->assertOk()
            ->assertDontSee('Power bank 17000mAh UK')
            ->assertSee(__('Nothing matches :term.', ['term' => '40000']));
    }

    // ---- Suggestions while typing ----------------------------------------

    /** *"sugest some result may i dont now full name or sku"*. */
    public function test_half_a_name_suggests_the_product(): void
    {
        $this->buy($this->bazaar, 3);

        $groups = $this->actingAs($this->user())
            ->getJson(route('find.suggest', ['q' => 'Power ba']))
            ->assertOk()
            ->json('groups');

        $this->assertSame(__('Products'), $groups[0]['label']);
        $this->assertSame('Power bank 17000mAh UK', $groups[0]['items'][0]['label']);

        // ⚠️ Into the dossier, not the product's own record: the reader is on
        // this page asking what they can DO about the thing.
        $this->assertSame(route('find', ['product' => $this->pd->id]), $groups[0]['items'][0]['url']);
        $this->assertStringContainsString('3', $groups[0]['items'][0]['note']);
    }

    public function test_half_a_sku_suggests_it_too(): void
    {
        $groups = $this->actingAs($this->user())
            ->getJson(route('find.suggest', ['q' => '17-UK']))
            ->assertOk()
            ->json('groups');

        $this->assertSame('Power bank 17000mAh UK', $groups[0]['items'][0]['label']);
    }

    /**
     * ⚠️ *"barcode shuld fully typed then search"*. A barcode is never
     * half-known — it is scanned, and it arrives whole — so every prefix of one
     * would drag unrelated products into a list somebody is reading mid-type.
     */
    public function test_half_a_barcode_suggests_nothing_and_the_whole_one_suggests_it(): void
    {
        $this->actingAs($this->user())
            ->getJson(route('find.suggest', ['q' => '69724964']))
            ->assertOk()
            ->assertJsonPath('groups', []);

        $groups = $this->actingAs($this->user())
            ->getJson(route('find.suggest', ['q' => '6972496470268']))
            ->assertOk()
            ->json('groups');

        $this->assertSame('Power bank 17000mAh UK', $groups[0]['items'][0]['label']);
    }

    /** A search the reader actually asked for is still generous. */
    public function test_a_part_barcode_typed_into_the_search_itself_still_finds_it(): void
    {
        $this->actingAs($this->user())
            ->get(route('find', ['q' => '69724964']))
            ->assertOk()
            ->assertSee('Power bank 17000mAh UK');
    }

    public function test_suggestions_cover_people_and_documents_as_well(): void
    {
        $this->buy($this->bazaar, 3);
        $sale = $this->sell($this->karwan, 1);

        $groups = collect($this->actingAs($this->user())
            ->getJson(route('find.suggest', ['q' => 'Karwan']))
            ->assertOk()
            ->json('groups'))->keyBy('label');

        $this->assertSame('Karwan Ahmed', $groups[__('People')]['items'][0]['label']);

        $documents = collect($this->actingAs($this->user())
            ->getJson(route('find.suggest', ['q' => 'INV-1']))
            ->assertOk()
            ->json('groups'))->keyBy('label');

        $this->assertSame($sale->document_no, $documents[__('Documents')]['items'][0]['label']);
    }

    /** One letter is everybody's name; the box waits for a second. */
    public function test_one_character_suggests_nothing(): void
    {
        $this->actingAs($this->user())
            ->getJson(route('find.suggest', ['q' => 'P']))
            ->assertOk()
            ->assertJsonPath('groups', []);
    }

    /** ⚠️ A suggestion the reader may not open is still a fact they were not meant to have. */
    public function test_suggestions_are_behind_the_same_permissions_as_the_page(): void
    {
        $this->buy($this->bazaar, 3);
        $this->sell($this->karwan, 1);

        $nobody = User::factory()->create(['role' => User::ROLE_USER]);
        $nobody->permissions()->sync(Permission::where('key', 'auth.login')->pluck('id'));

        $this->actingAs($nobody)
            ->getJson(route('find.suggest', ['q' => 'Power ba']))
            ->assertOk()
            ->assertJsonPath('groups', []);

        $this->actingAs($nobody)
            ->getJson(route('find.suggest', ['q' => 'Karwan']))
            ->assertOk()
            ->assertJsonPath('groups', []);

        $this->actingAs($nobody)
            ->getJson(route('find.suggest', ['q' => 'INV-1']))
            ->assertOk()
            ->assertJsonPath('groups', []);
    }

    public function test_eastern_digits_suggest_too(): void
    {
        $this->buy($this->bazaar, 3);
        $sale = $this->sell($this->karwan, 1);

        $groups = collect($this->actingAs($this->user())
            ->getJson(route('find.suggest', ['q' => 'INV-٠٠٠٠١']))
            ->assertOk()
            ->json('groups'))->keyBy('label');

        $this->assertSame($sale->document_no, $groups[__('Documents')]['items'][0]['label']);
    }

    /**
     * The box carries what it needs for app.js to attach the type-ahead, and
     * keeps the term so the next scan replaces a selected one.
     */
    public function test_the_box_is_wired_for_suggestions_and_keeps_the_term(): void
    {
        $html = $this->actingAs($this->user())
            ->get(route('find', ['q' => 'PD-17-UK']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="find-q"', $html);
        $this->assertStringContainsString('data-url="'.e(route('find.suggest')).'"', $html);
        $this->assertStringContainsString('aria-controls="find-suggestions"', $html);
        $this->assertMatchesRegularExpression('/id="find-q"[^>]*value="PD-17-UK"/', $html);
    }

    // ---- Permissions ------------------------------------------------------

    /**
     * ⚠️ A page that says "you bought 40 of these for 1,600,000" has told a
     * reader what the purchases screen was keeping from them.
     */
    public function test_a_reader_without_purchases_sees_no_spend_and_no_supplier(): void
    {
        $this->buy($this->bazaar, 10, 40_000);
        $this->sell($this->karwan, 1);

        $counter = User::factory()->create(['role' => User::ROLE_USER]);
        $counter->permissions()->sync(
            Permission::whereIn('key', ['products.view', 'sales.view', 'customers.view'])->pluck('id')
        );

        $page = $this->actingAs($counter)->get(route('find', ['q' => 'PD-17-UK']));

        $page->assertOk()
            ->assertSee('Power bank 17000mAh UK')
            ->assertDontSee(__('You buy it from'))
            ->assertDontSee(__('Spent on it'))
            ->assertDontSee(__('Purchases that brought it in'))
            ->assertDontSee('Bazaar Mobile')
            ->assertDontSee(money(400_000, false));

        // What they may see, they still see.
        $page->assertSee(__('Your best customer for it'))->assertSee('Karwan Ahmed');

        $this->assertNull($page->viewData('bought'));
        $this->assertNull($page->viewData('bestSupplier'));
    }

    public function test_a_reader_without_sales_sees_no_invoices_and_no_customer(): void
    {
        $this->buy($this->bazaar, 10, 40_000);
        $this->sell($this->karwan, 1);

        $buyer = User::factory()->create(['role' => User::ROLE_USER]);
        $buyer->permissions()->sync(
            Permission::whereIn('key', ['products.view', 'purchases.view', 'suppliers.view'])->pluck('id')
        );

        $page = $this->actingAs($buyer)->get(route('find', ['q' => 'PD-17-UK']));

        $page->assertOk()
            ->assertDontSee(__('Invoices that sold it'))
            ->assertDontSee(__('Your best customer for it'))
            ->assertDontSee('Karwan Ahmed')
            ->assertSee(__('You buy it from'));

        $this->assertNull($page->viewData('sold'));
        $this->assertNull($page->viewData('bestCustomer'));
    }

    /**
     * The page itself is open to everybody, and answers with nothing when the
     * reader may see nothing. ⚠️ Guarding the route instead would mean
     * inventing a key for "may look things up", which is every job in the shop.
     */
    public function test_the_page_opens_for_a_reader_with_no_keys_and_tells_them_nothing(): void
    {
        $this->buy($this->bazaar, 10);
        $this->sell($this->karwan, 1);

        $nobody = User::factory()->create(['role' => User::ROLE_USER]);
        $nobody->permissions()->sync(Permission::where('key', 'auth.login')->pluck('id'));

        $this->actingAs($nobody)
            ->get(route('find', ['q' => 'PD-17-UK']))
            ->assertOk()
            ->assertDontSee('Power bank 17000mAh UK')
            ->assertSee(__('Nothing matches :term.', ['term' => 'PD-17-UK']));
    }

    /** A deleted document does not come back through the other half of the OR. */
    public function test_a_deleted_invoice_is_not_found(): void
    {
        $this->buy($this->bazaar, 5);
        $sale = $this->sell($this->karwan, 1);
        $number = $sale->document_no;

        $sale->delete();

        $this->actingAs($this->user())
            ->get(route('find', ['q' => $number]))
            ->assertOk()
            ->assertDontSee(route('sales.show', $sale->id), false);
    }
}
