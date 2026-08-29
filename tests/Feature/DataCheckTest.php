<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DataIntegrityService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The data check, checked.
 *
 * A page that says "everything agrees" is worth exactly as much as the evidence
 * that it would have said otherwise. So every check here is proved twice: once
 * against a shop that really traded — bought, sold, returned, adjusted, paid —
 * where it must stay quiet, and once against that same shop with one row broken
 * on purpose, where it must speak up and name the row.
 *
 * The first half matters as much as the second. A check that cries wolf on good
 * data is worse than no check, because the evening it finds something real is
 * the evening nobody believes it.
 */
class DataCheckTest extends TestCase
{
    use RefreshDatabase;

    /** This suite writes corrupt rows on purpose; that is the whole point of it. */
    protected bool $breaksInvariantsOnPurpose = true;

    private User $admin;

    private Product $usb;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin);

        $category = Category::create(['name' => 'Accessories']);

        $this->usb = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000,
            'quantity' => 0, 'is_active' => true,
        ]);

        $this->customer = Customer::create(['name' => 'Karwan']);

        // A shop that actually traded: two purchases at different costs so FIFO
        // has layers, a sale that crosses them, a partial return, an adjustment.
        $supplier = Supplier::create(['name' => 'Bazaar Mobile']);

        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->usb->id, 'quantity' => 6, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now()->subDays(5), amountPaid: 30_000,
        );

        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->usb->id, 'quantity' => 4, 'unit_price' => 12_000]],
            user: $this->admin, purchaseDate: now()->subDays(4), amountPaid: 0,
            discountAmount: 2_000,
        );

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->usb->id, 'quantity' => 8, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now()->subDays(2), amountPaid: 60_000,
        );

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->first()->id, 'quantity' => 2]],
            user: $this->admin, returnDate: now()->subDay(),
        );

        app(StockAdjustmentService::class)->create(
            product: $this->usb->refresh(), direction: StockAdjustment::DIRECTION_OUT,
            quantity: 1, reason: 'damage', user: $this->admin,
        );
    }

    /** @return array<string, mixed> */
    private function check(string $key): array
    {
        $report = app(DataIntegrityService::class)->run();

        foreach ($report['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        $this->fail("There is no check called [{$key}].");
    }

    /** @return list<string> keys of every check that is not OK */
    private function complaints(): array
    {
        return collect(app(DataIntegrityService::class)->run()['checks'])
            ->reject(fn ($c) => $c['severity'] === DataIntegrityService::OK)
            ->pluck('key')->all();
    }

    // =====================================================================
    // The half that matters most: silence on good data.
    // =====================================================================

    public function test_a_shop_that_really_traded_reports_nothing(): void
    {
        $report = app(DataIntegrityService::class)->run();

        $this->assertSame([], $this->complaints(), 'a healthy shop trips nothing');
        $this->assertSame(0, $report['serious']);
        $this->assertSame(0, $report['rebuildable']);
        $this->assertGreaterThan(0, $report['rows'], 'it actually read something');
    }

    /** Deleting a document keeps its movements as reversals. That is not damage. */
    public function test_a_deleted_sale_is_not_reported_as_damage(): void
    {
        // Its own sale, because the one from setUp has a return against it and
        // is locked — which is Section 8 working, not something to work around.
        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->usb->id, 'quantity' => 1, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 15_000,
        );

        app(SaleService::class)->delete($sale, $this->admin);

        $this->assertSame([], $this->complaints(), 'a reversed sale still agrees with itself');

        // Worth being explicit about, because it is the reason the document
        // checks look only at live rows: reversing a sale removes its lines, so
        // a deleted invoice keeps a total with nothing behind it. That is by
        // design — the lines it had are in the activity log's snapshot — but
        // reported as damage it would cry wolf on every delete the shop makes.
        $this->assertSame(0, $sale->items()->count());
        $this->assertGreaterThan(0, (int) $sale->fresh()->total_amount);
    }

    /** A soft-deleted product keeps its batches and its numbers. */
    public function test_a_deleted_product_is_not_reported_as_damage(): void
    {
        Product::create([
            'name' => 'Typo', 'sku' => 'TYPO1', 'category_id' => Category::first()->id,
            'unit' => 'pcs', 'purchase_price' => 1, 'sale_price' => 2,
            'quantity' => 0, 'is_active' => true,
        ])->delete();

        $this->assertSame([], $this->complaints());
    }

    /** A service sells with no batch and no movement, and that is the design. */
    public function test_a_service_is_not_reported_as_damage(): void
    {
        $service = Product::create([
            'name' => 'Screen repair', 'sku' => 'FIX1', 'category_id' => Category::first()->id,
            'kind' => Product::KIND_SERVICE, 'unit' => 'pcs', 'purchase_price' => 0,
            'sale_price' => 10_000, 'quantity' => 0, 'is_active' => true,
        ]);

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 10_000]],
            user: $this->admin, saleDate: now(), amountPaid: 10_000,
        );

        $this->assertSame([], $this->complaints());
    }

    // =====================================================================
    // Stock
    // =====================================================================

    public function test_it_catches_a_stock_figure_that_drifted(): void
    {
        DB::table('products')->where('id', $this->usb->id)->update(['quantity' => 99]);

        $check = $this->check('stock_cache');

        $this->assertSame(DataIntegrityService::REBUILDABLE, $check['severity']);
        $this->assertSame(1, $check['failed']);
        $this->assertStringContainsString('USB 32GB', $check['examples'][0]['what']);
        $this->assertSame('stock.recheck', $check['repair'], 'and it offers the repair');
    }

    public function test_it_catches_a_batch_holding_more_than_was_bought(): void
    {
        DB::table('stock_batches')->orderBy('id')->limit(1)->update(['quantity_remaining' => 999]);

        $check = $this->check('batch_bounds');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
    }

    /** The deep one: a batch whose own movements no longer add up to it. */
    public function test_it_catches_a_batch_that_disagrees_with_its_movements(): void
    {
        $batch = DB::table('stock_batches')->orderBy('id')->first();

        // One unit quietly taken off the batch and nowhere else.
        DB::table('stock_batches')->where('id', $batch->id)
            ->update(['quantity_remaining' => $batch->quantity_remaining + 1]);

        $check = $this->check('batch_ledger');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
        $this->assertStringContainsString((string) $batch->id, $check['examples'][0]['what']);
    }

    public function test_it_catches_a_sale_movement_that_lost_its_line(): void
    {
        DB::table('stock_movements')->where('reference_type', 'sale')
            ->orderBy('id')->limit(1)->update(['reference_item_id' => null]);

        $check = $this->check('movement_line');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
    }

    public function test_it_catches_a_service_carrying_stock(): void
    {
        $service = Product::create([
            'name' => 'Screen repair', 'sku' => 'FIX1', 'category_id' => Category::first()->id,
            'kind' => Product::KIND_SERVICE, 'unit' => 'pcs', 'purchase_price' => 0,
            'sale_price' => 10_000, 'quantity' => 0, 'is_active' => true,
        ]);

        DB::table('products')->where('id', $service->id)->update(['quantity' => 3]);

        $this->assertSame(1, $this->check('service_stock')['failed']);
    }

    // =====================================================================
    // Money
    // =====================================================================

    public function test_it_catches_a_balance_that_disagrees_with_the_ledger(): void
    {
        DB::table('customers')->where('id', $this->customer->id)->update(['balance' => 12_345]);

        $check = $this->check('balance_cache');

        $this->assertSame(DataIntegrityService::REBUILDABLE, $check['severity']);
        $this->assertSame(1, $check['failed']);
        $this->assertStringContainsString('Karwan', $check['examples'][0]['what']);
    }

    /**
     * The check that a "balance equals the last line" test would not survive.
     *
     * An entry is removed from the middle of the ledger. The final balance_after
     * still matches the cached balance, so the simpler check passes — and the
     * shop's account is missing an entry.
     */
    public function test_it_catches_a_ledger_entry_missing_from_the_middle(): void
    {
        $middle = DB::table('account_transactions')
            ->where('accountable_type', 'customer')
            ->orderBy('id')->skip(1)->take(1)->first();

        DB::table('account_transactions')->where('id', $middle->id)->delete();

        $this->assertSame(
            DataIntegrityService::OK,
            $this->check('balance_cache')['severity'],
            'the last line still matches, which is exactly why this needs its own check',
        );

        $chain = $this->check('ledger_chain');

        $this->assertSame(DataIntegrityService::SERIOUS, $chain['severity']);
        $this->assertGreaterThan(0, $chain['failed']);
    }

    public function test_it_catches_an_invoice_that_does_not_add_up(): void
    {
        DB::table('sales')->orderBy('id')->limit(1)->update(['total_amount' => 1_000_000]);

        $check = $this->check('document_totals');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
        $this->assertStringContainsString('INV-', $check['examples'][0]['what']);
    }

    public function test_it_catches_a_grand_total_that_ignores_its_discount(): void
    {
        DB::table('purchases')->orderBy('id')->limit(1)->update(['discount_amount' => 5_000]);

        $this->assertGreaterThan(0, $this->check('document_totals')['failed']);
    }

    public function test_it_catches_a_return_that_does_not_add_up(): void
    {
        DB::table('sale_returns')->orderBy('id')->limit(1)->update(['total_amount' => 7]);

        $this->assertSame(1, $this->check('return_totals')['failed']);
    }

    public function test_it_catches_a_document_paid_more_than_it_came_to(): void
    {
        $sale = DB::table('sales')->orderBy('id')->first();

        DB::table('payments')->where('payable_type', 'sale')->where('payable_id', $sale->id)
            ->update(['amount' => $sale->total_amount + 50_000]);

        $check = $this->check('overpaid');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
    }

    public function test_it_catches_a_walk_in_customer_left_owing(): void
    {
        DB::table('customers')->where('is_system', true)->update(['balance' => 25_000]);

        $check = $this->check('cash_customer');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
    }

    // =====================================================================
    // Documents
    // =====================================================================

    /**
     * Every document_no carries a unique index, so this cannot happen while the
     * index is there — which is the good news, and is why the index has to go
     * first for the check to have anything to find.
     *
     * That is not a contrived scenario. A restore from a dump taken with the
     * wrong flags, or a table rebuilt by hand to fix something else, is exactly
     * how an index goes missing — and it goes missing quietly, because nothing
     * fails until two documents collide months later.
     */
    public function test_it_catches_a_document_number_used_twice(): void
    {
        $first = DB::table('sales')->orderBy('id')->first();

        DB::statement('drop index sales_document_no_unique');

        DB::table('sales')->insert([
            'document_no' => $first->document_no,
            'customer_id' => $first->customer_id,
            'user_id' => $first->user_id,
            'total_amount' => 0, 'status' => 'active',
            'sale_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $check = $this->check('duplicate_numbers');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
    }

    /**
     * The one worth catching early: not wrong yet, wrong on the next sale.
     */
    public function test_it_catches_a_counter_that_fell_behind(): void
    {
        DB::table('document_counters')->where('prefix', 'INV')->update(['next_number' => 1]);

        $check = $this->check('counters');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
        $this->assertStringContainsString('INV', $check['examples'][0]['what']);
    }

    public function test_it_catches_more_returned_than_was_ever_sold(): void
    {
        DB::table('sale_items')->orderBy('id')->limit(1)->update(['quantity_returned' => 99]);

        $this->assertSame(1, $this->check('over_returned')['failed']);
    }

    public function test_it_catches_a_status_that_disagrees_with_its_lines(): void
    {
        DB::table('sales')->orderBy('id')->limit(1)->update(['status' => 'returned']);

        $check = $this->check('derived_status');

        $this->assertSame(DataIntegrityService::REBUILDABLE, $check['severity']);
        $this->assertSame(1, $check['failed']);
    }

    // =====================================================================
    // Links
    // =====================================================================

    /** The joins Section 5 says the database is not watching. */
    public function test_it_catches_a_payment_against_an_invoice_that_is_not_there(): void
    {
        DB::table('payments')->where('payable_type', 'sale')
            ->orderBy('id')->limit(1)->update(['payable_id' => 987654]);

        $check = $this->check('orphan_links');

        $this->assertSame(DataIntegrityService::SERIOUS, $check['severity']);
        $this->assertSame(1, $check['failed']);
    }

    public function test_it_catches_a_batch_whose_purchase_is_not_there(): void
    {
        DB::table('stock_batches')->where('source_type', 'purchase')
            ->orderBy('id')->limit(1)->update(['source_id' => 987654]);

        $this->assertSame(1, $this->check('orphan_links')['failed']);
    }

    /** One thing broken should light one lamp, not the whole board. */
    public function test_one_broken_row_lights_exactly_one_check(): void
    {
        DB::table('document_counters')->where('prefix', 'INV')->update(['next_number' => 1]);

        $this->assertSame(['counters'], $this->complaints());
    }

    // =====================================================================
    // The engine underneath
    // =====================================================================

    /**
     * The bug that reached the shop: `as lines`.
     *
     * LINES is a reserved word in MariaDB and an ordinary one in SQLite, so the
     * whole suite passed and the live page answered with a syntax error. The
     * suite cannot catch that by running the SQL — it does not have MariaDB —
     * so it catches it by the rule that makes it impossible instead: every
     * alias this service invents starts with chk_, and no reserved word in any
     * engine, present or future, starts with chk_.
     */
    public function test_every_invented_alias_is_safe_on_any_engine(): void
    {
        /*
         * The SQL string literals, and only those.
         *
         * An alias lives inside a string, so the comments are out — but so is
         * the English inside __(), which says "costed as if it were a thing on
         * a shelf" and is not an alias called `if`. A test that has to be
         * argued with is a test somebody eventually deletes.
         */
        $tokens = token_get_all(file_get_contents(app_path('Services/DataIntegrityService.php')));
        $significant = array_values(array_filter(
            $tokens,
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $strings = [];

        foreach ($significant as $i => $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $opener = $significant[$i - 1] ?? null;
            $callee = $significant[$i - 2] ?? null;

            $isSentence = $opener === '('
                && is_array($callee)
                && in_array($callee[1], ['__', 'trans_choice'], true);

            if (! $isSentence) {
                $strings[] = $token[1];
            }
        }

        preg_match_all('/\bas ([a-z_][a-z0-9_]*)/i', implode("\n", $strings), $matches);

        $aliases = array_values(array_unique(array_filter(
            $matches[1],
            // Single letters are the table aliases in the same statement, which
            // the query builder quotes and which are never keywords.
            fn (string $alias) => strlen($alias) > 1,
        )));

        $unsafe = array_values(array_filter(
            $aliases,
            fn (string $alias) => ! str_starts_with($alias, 'chk_'),
        ));

        $this->assertSame([], $unsafe,
            'Every alias must start with chk_ so it cannot collide with a reserved word: '
            .implode(', ', $unsafe));

        $this->assertNotEmpty($aliases, 'the pattern still finds the aliases');
    }

    /**
     * One check failing must cost one check, not the page.
     *
     * This is the lesson of the reserved word rather than the word itself: the
     * page whose whole job is to say what is wrong is the last page that may
     * answer a problem with a stack trace.
     */
    public function test_a_check_that_cannot_run_costs_only_itself(): void
    {
        // The table two checks need, gone — which is what a half-run migration,
        // or a restore of the wrong dump, actually looks like.
        Schema::drop('account_transactions');

        $report = app(DataIntegrityService::class)->run();

        $this->assertSame(17, count($report['checks']), 'every check still reported');
        $this->assertGreaterThan(0, $report['unavailable'], 'the broken ones said so');

        $broken = collect($report['checks'])
            ->where('severity', DataIntegrityService::UNAVAILABLE);

        $this->assertTrue($broken->contains('key', 'ledger_chain'));
        $this->assertTrue($broken->contains('key', 'balance_cache'));

        // And it never pretends. A check that could not run says nothing rather
        // than "agrees", which would be the dangerous answer.
        foreach ($broken as $check) {
            $this->assertNotSame(DataIntegrityService::OK, $check['severity']);
            $this->assertNotEmpty($check['examples'][0]['says'], 'it says why');
        }

        // The checks that do not touch that table still did their work.
        $counters = collect($report['checks'])->firstWhere('key', 'counters');
        $this->assertSame(DataIntegrityService::OK, $counters['severity']);
    }

    /** And the page renders it rather than falling over. */
    public function test_the_page_survives_a_check_that_cannot_run(): void
    {
        Schema::drop('account_transactions');

        $this->get(route('settings.data-check'))
            ->assertOk()
            ->assertSee(__('Did not run'), false)
            ->assertSee(__('The next document number is ahead of every one already used'), false);
    }

    // =====================================================================
    // The page
    // =====================================================================

    public function test_the_page_reports_a_healthy_shop(): void
    {
        $this->get(route('settings.data-check'))
            ->assertOk()
            ->assertSee(__('Everything agrees.'), false);
    }

    public function test_the_page_names_what_is_wrong(): void
    {
        DB::table('products')->where('id', $this->usb->id)->update(['quantity' => 99]);

        $this->get(route('settings.data-check'))
            ->assertOk()
            ->assertSee(__('Can be rebuilt'), false)
            ->assertSee('USB 32GB', false)
            ->assertSee(__('Recheck stock'), false);
    }

    public function test_it_needs_the_settings_permission(): void
    {
        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'products.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('settings.data-check'))->assertForbidden();
    }

    /** It reads. It never writes. */
    public function test_running_it_changes_nothing(): void
    {
        $before = [];

        foreach (['products', 'stock_batches', 'stock_movements', 'account_transactions',
            'sales', 'purchases', 'payments', 'customers', 'document_counters'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }

        app(DataIntegrityService::class)->run();

        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->get()->toJson(), "{$table} was not touched");
        }
    }
}
