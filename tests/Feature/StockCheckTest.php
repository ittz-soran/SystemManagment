<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockCheck;
use App\Models\StockCheckLine;
use App\Models\StockRoom;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockCheckService;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The stock check — PROJECT_DOC Section 9. Soran, 2026-10-30: *"stock check
 * manually, for ex in Stock Check show PD-17L-UK In stock, batches,
 * movments... and Show Confirm (mean all correct not need movment), Skip (Show
 * another product), Wrong (mean need correction by ADJ or return)"*, with the
 * count typed, rounds with progress, scan to open, a blind count, last
 * checked on products, and a report at the end.
 */
class StockCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $cables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->cables = Category::firstOrCreate(['name' => 'Cables']);
    }

    /** A product with this many in stock, bought at 1,000 each. */
    private function product(string $name, int $stock, ?Category $category = null, string $kind = Product::KIND_STOCK, ?string $barcode = null): Product
    {
        $product = Product::create([
            'name' => $name, 'sku' => 'S-'.$name, 'barcode' => $barcode, 'kind' => $kind,
            'category_id' => ($category ?? $this->cables)->id, 'unit' => 'pcs',
            'purchase_price' => 1_000, 'sale_price' => 2_000, 'quantity' => 0,
        ]);

        if ($stock > 0) {
            app(PurchaseService::class)->create(
                supplier: Supplier::firstOrCreate(['name' => 'Click Gaming']),
                lines: [['product_id' => $product->id, 'quantity' => $stock, 'unit_price' => 1_000]],
                user: $this->admin, purchaseDate: now(),
            );
        }

        return $product->fresh();
    }

    private function start(array $form = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('stock-checks.store'), ['scope' => 'all', ...$form]);
    }

    private function round(): StockCheck
    {
        return StockCheck::latest('id')->firstOrFail();
    }

    private function lineOf(Product $product, ?StockCheck $check = null): StockCheckLine
    {
        return ($check ?? $this->round())->lines()->where('product_id', $product->id)->firstOrFail();
    }

    private function held(Product $product): int
    {
        return (int) StockBatch::where('product_id', $product->id)->sum('quantity_remaining');
    }

    private function sellOne(Product $product): void
    {
        app(SaleService::class)->create(
            customer: Customer::cashCustomer(),
            lines: [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 2_000]],
            user: $this->admin, saleDate: now(), amountPaid: 2_000,
        );
    }

    // ---- a round ---------------------------------------------------------------

    /** What the system has in stock, in category and then name order; never a service. */
    public function test_a_round_walks_what_is_in_stock_in_shelf_order(): void
    {
        $phones = Category::create(['name' => 'Phones']);
        $b = $this->product('Cable no.10', 3);
        $a = $this->product('Cable no.2', 2);
        $phone = $this->product('Phone', 1, $phones);
        $this->product('Cable at zero', 0);
        $this->product('Screen fitting', 0, kind: Product::KIND_SERVICE);

        $this->start()->assertRedirect();

        $check = $this->round();
        $this->assertSame('CHK-00001', $check->document_no);
        $this->assertSame(
            [$a->id, $b->id, $phone->id],
            $check->lines()->orderBy('position')->pluck('product_id')->all(),
        );
    }

    public function test_the_ones_at_zero_come_in_when_asked_for(): void
    {
        $this->product('Cable A', 2);
        $zero = $this->product('Cable at zero', 0);
        $service = $this->product('Screen fitting', 0, kind: Product::KIND_SERVICE);

        $this->start(['with_empty' => 1]);

        $this->assertTrue($this->round()->lines()->where('product_id', $zero->id)->exists());
        // A service holds no stock, so it is never counted — at zero or not.
        $this->assertFalse($this->round()->lines()->where('product_id', $service->id)->exists());
    }

    public function test_a_round_of_one_category(): void
    {
        $phone = $this->product('Phone', 1, Category::create(['name' => 'Phones']));
        $this->product('Cable A', 2);

        $this->start(['scope' => 'category', 'category_id' => $phone->category_id]);

        $this->assertSame([$phone->id], $this->round()->lines()->pluck('product_id')->all());
    }

    /** A round of one room counts what that room holds. */
    public function test_a_round_of_one_room_counts_that_room(): void
    {
        $cable = $this->product('Cable A', 5);
        $back = StockRoom::create(['name' => 'Back room', 'is_main' => false, 'is_active' => true]);
        app(TransferService::class)->create(StockRoom::main(), $back, [['product_id' => $cable->id, 'quantity' => 2]], now(), null, $this->admin);

        $this->start(['scope' => 'room', 'room_id' => $back->id]);

        $check = $this->round();
        $this->assertSame(2, app(StockCheckService::class)->systemQuantity($check, $cable));

        // ⚠️ An adjustment acts on the main room only, so a back room's
        // difference is fixed later, with the reason.
        $line = $this->lineOf($cable, $check);
        $this->actingAs($this->admin)->post(route('stock-checks.answer', [$check, $line]), [
            'seen' => 2, 'counted' => 1, 'fix' => 'adjusted',
        ])->assertSessionHas('error');

        $this->assertSame(StockCheckLine::PENDING, $line->fresh()->status);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_nothing_to_count_is_said_so(): void
    {
        $this->start()->assertSessionHas('error', __('There is nothing to count there.'));
        $this->assertSame(0, StockCheck::count());
    }

    // ---- the answers -------------------------------------------------------------

    /** Confirm: the shelf agrees, and nothing moves. */
    public function test_confirm_moves_nothing(): void
    {
        $cable = $this->product('Cable A', 5);
        $this->start();
        $line = $this->lineOf($cable);

        $this->actingAs($this->admin)->post(route('stock-checks.confirm', [$this->round(), $line]), ['seen' => 5])
            ->assertRedirect(route('stock-checks.next', $this->round()));

        $line->refresh();
        $this->assertSame([StockCheckLine::CONFIRMED, 5, 5], [$line->status, $line->expected, $line->counted]);
        $this->assertSame($this->admin->id, $line->checked_by);
        $this->assertSame(5, $this->held($cable));
        $this->assertSame(0, StockAdjustment::count());
    }

    /**
     * ⚠️ The stock is read again when the answer is saved. A sale at the till
     * while the shelf was being counted refuses the answer.
     */
    public function test_an_answer_against_a_figure_that_moved_is_refused(): void
    {
        $cable = $this->product('Cable A', 5);
        $this->start();
        $line = $this->lineOf($cable);

        $this->sellOne($cable); // the screen said 5; the system now says 4

        $this->actingAs($this->admin)->post(route('stock-checks.confirm', [$this->round(), $line]), ['seen' => 5])
            ->assertRedirect(route('stock-checks.line', [$this->round(), $line]))
            ->assertSessionHas('error', __('The stock of this product changed while it was being counted — it now says :now. Count it again.', ['now' => 4]));

        $this->assertSame(StockCheckLine::PENDING, $line->fresh()->status);

        // The same for a count, which would otherwise read as one too many.
        $this->actingAs($this->admin)->post(route('stock-checks.answer', [$this->round(), $line]), [
            'seen' => 5, 'counted' => 5, 'fix' => 'later',
        ])->assertSessionHas('error');
        $this->assertSame(StockCheckLine::PENDING, $line->fresh()->status);
    }

    /** Skip: it comes back after every product not yet looked at, longest skipped first. */
    public function test_skipped_ones_come_back_after_the_rest(): void
    {
        $a = $this->product('Cable A', 1);
        $b = $this->product('Cable B', 1);
        $c = $this->product('Cable C', 1);
        $this->start();
        $check = $this->round();
        $service = app(StockCheckService::class);

        $this->actingAs($this->admin)->post(route('stock-checks.skip', [$check, $this->lineOf($a)]));
        $this->assertSame($b->id, $service->next($check)->product_id);

        $this->actingAs($this->admin)->post(route('stock-checks.confirm', [$check, $this->lineOf($b)]), ['seen' => 1]);
        $this->actingAs($this->admin)->post(route('stock-checks.skip', [$check, $this->lineOf($c)]));

        // Nothing pending: the skipped ones, A first because it waited longest.
        $this->travel(1)->seconds();
        $this->assertSame($a->id, $service->next($check)->product_id);
        $this->actingAs($this->admin)->post(route('stock-checks.skip', [$check, $this->lineOf($a)]));
        $this->assertSame($c->id, $service->next($check)->product_id);
    }

    /** Wrong, put right now: the ADJ out, taken FIFO, with the round as its reason. */
    public function test_wrong_with_the_adj_now_takes_the_missing_units_out(): void
    {
        $cable = $this->product('Cable A', 5);
        $this->start();
        $line = $this->lineOf($cable);

        $this->actingAs($this->admin)->post(route('stock-checks.answer', [$this->round(), $line]), [
            'seen' => 5, 'counted' => 3, 'fix' => 'adjusted', 'note' => 'two gone',
        ])->assertSessionHasNoErrors()->assertRedirect(route('stock-checks.next', $this->round()));

        $line->refresh();
        $adjustment = $line->adjustment;
        $this->assertSame([StockCheckLine::WRONG, 5, 3, -2], [$line->status, $line->expected, $line->counted, $line->difference()]);
        $this->assertSame(StockCheckLine::FIX_ADJUSTED, $line->fix);
        $this->assertSame([StockAdjustment::DIRECTION_OUT, 2], [$adjustment->direction, (int) $adjustment->quantity]);
        $this->assertSame(__('Stock check :number', ['number' => 'CHK-00001']), $adjustment->reason);
        $this->assertSame(3, $this->held($cable));
        $this->assertSame(['value' => -2_000, 'estimate' => false], app(StockCheckService::class)->value($line));
    }

    /** More on the shelf than the system says: the ADJ in, at the cost given. */
    public function test_wrong_with_more_on_the_shelf_brings_them_in_at_a_cost(): void
    {
        $cable = $this->product('Cable A', 5);
        $this->start();
        $line = $this->lineOf($cable);

        $this->actingAs($this->admin)->post(route('stock-checks.answer', [$this->round(), $line]), [
            'seen' => 5, 'counted' => 7, 'fix' => 'adjusted', 'unit_cost' => 1_250,
        ])->assertSessionHasNoErrors();

        $adjustment = $line->fresh()->adjustment;
        $this->assertSame([StockAdjustment::DIRECTION_IN, 2, 1_250], [$adjustment->direction, (int) $adjustment->quantity, (int) $adjustment->unit_cost]);
        $this->assertSame(7, $this->held($cable));
    }

    /** Wrong, fixed later: on the To fix list, until the ADJ is made or it is marked fixed. */
    public function test_wrong_fixed_later_waits_on_the_to_fix_list(): void
    {
        $a = $this->product('Cable A', 5);
        $b = $this->product('Cable B', 5);
        $this->start();
        $check = $this->round();

        foreach ([[$a, 4, 'Hawkar has one'], [$b, 6, 'a second shelf']] as [$product, $counted, $note]) {
            $this->actingAs($this->admin)->post(route('stock-checks.answer', [$check, $this->lineOf($product)]), [
                'seen' => 5, 'counted' => $counted, 'fix' => 'later', 'note' => $note,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(5, $this->held($a));
        $list = $this->actingAs($this->admin)->get(route('stock-checks.to-fix'))->assertOk();
        $list->assertSee('Hawkar has one')->assertSee('a second shelf');

        // One put right by an ADJ from the list, one by a return marked fixed.
        $this->actingAs($this->admin)->post(route('stock-checks.adjust', $this->lineOf($a)))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('stock-checks.fixed', $this->lineOf($b)), ['note' => 'SRT-00012'])->assertSessionHas('success');

        $this->assertSame(4, $this->held($a));
        $this->assertSame(StockCheckLine::FIX_FIXED, $this->lineOf($b)->fix);
        $this->assertStringContainsString('SRT-00012', $this->lineOf($b)->note);
        $this->actingAs($this->admin)->get(route('stock-checks.to-fix'))->assertDontSee('Hawkar has one');

        // And neither can be put right twice.
        $this->actingAs($this->admin)->post(route('stock-checks.adjust', $this->lineOf($a)))->assertSessionHas('error');
        $this->assertSame(1, StockAdjustment::count());
    }

    /** "Wrong" with the count that agrees is a confirm. */
    public function test_a_wrong_count_that_agrees_is_a_confirm(): void
    {
        $cable = $this->product('Cable A', 5);
        $this->start();

        $this->actingAs($this->admin)->post(route('stock-checks.answer', [$this->round(), $this->lineOf($cable)]), [
            'seen' => 5, 'counted' => 5, 'fix' => 'adjusted',
        ]);

        $this->assertSame(StockCheckLine::CONFIRMED, $this->lineOf($cable)->status);
        $this->assertSame(0, StockAdjustment::count());
    }

    /** Making the ADJ needs the permission to adjust; fixing later does not. */
    public function test_a_counter_who_may_not_adjust_can_only_fix_later(): void
    {
        $cable = $this->product('Cable A', 5);
        $counter = $this->staff(['stock_checks.view', 'stock_checks.count']);
        $this->start();
        $line = $this->lineOf($cable);

        $this->actingAs($counter)->post(route('stock-checks.answer', [$this->round(), $line]), [
            'seen' => 5, 'counted' => 3, 'fix' => 'adjusted',
        ])->assertSessionHas('error', __('Making an adjustment needs the permission to create stock adjustments.'));
        $this->assertSame(StockCheckLine::PENDING, $line->fresh()->status);

        $this->actingAs($counter)->post(route('stock-checks.answer', [$this->round(), $line]), [
            'seen' => 5, 'counted' => 3, 'fix' => 'later',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($line->fresh()->isToFix());
    }

    // ---- blind, scan, finish -------------------------------------------------------

    /** Blind: the system's figure is hidden until the count is typed. */
    public function test_a_blind_count_hides_the_figure_until_the_count(): void
    {
        $cable = $this->product('Cable A', 5);
        $this->start(['blind' => 1]);
        $check = $this->round();
        $line = $this->lineOf($cable);

        $this->actingAs($this->admin)->get(route('stock-checks.line', [$check, $line]))
            ->assertOk()->assertDontSee('data-system', false)->assertSee('name="counted"', false);

        // A count that disagrees shows everything, the count already in.
        $this->actingAs($this->admin)->post(route('stock-checks.count', [$check, $line]), ['seen' => 5, 'counted' => 4])
            ->assertRedirect(route('stock-checks.line', [$check, $line]))->assertSessionHas('counted', 4);
        $this->assertSame(StockCheckLine::PENDING, $line->fresh()->status);
        $this->actingAs($this->admin)->withSession(['counted' => 4])->get(route('stock-checks.line', [$check, $line]))
            ->assertSee('data-system', false)->assertSee('value="4"', false);

        // One that agrees is confirmed.
        $this->actingAs($this->admin)->post(route('stock-checks.count', [$check, $line]), ['seen' => 5, 'counted' => 5]);
        $this->assertSame(StockCheckLine::CONFIRMED, $line->fresh()->status);

        // ⚠️ A sale during a blind count is refused too: the shelf's 5 against
        // the 4 left would otherwise go on as one too many.
        $other = $this->product('Cable B', 5);
        $this->start(['blind' => 1]);
        $second = $this->round();
        $otherLine = $second->lines()->where('product_id', $other->id)->firstOrFail();
        $this->sellOne($other);

        $this->actingAs($this->admin)->post(route('stock-checks.count', [$second, $otherLine]), ['seen' => 5, 'counted' => 5])
            ->assertSessionHas('error', __('The stock of this product changed while it was being counted — it now says :now. Count it again.', ['now' => 4]))
            ->assertSessionMissing('counted');
        $this->assertSame(StockCheckLine::PENDING, $otherLine->fresh()->status);
    }

    /** Scan to open: a barcode, or a code typed in Kurdish digits, opens that product. */
    public function test_scanning_a_code_opens_that_product(): void
    {
        $this->product('Cable A', 1);
        $b = $this->product('Cable B', 1, barcode: '6009880012');
        $outside = $this->product('Phone', 1, Category::create(['name' => 'Phones']));
        // An earlier round holds the phone; this one, of cables, does not.
        $this->start();
        $this->start(['scope' => 'category', 'category_id' => $this->cables->id]);
        $check = $this->round();

        $this->actingAs($this->admin)->get(route('stock-checks.scan', [$check, 'code' => '٦٠٠٩٨٨٠٠١٢']))
            ->assertRedirect(route('stock-checks.line', [$check, $this->lineOf($b)]));

        $this->actingAs($this->admin)->get(route('stock-checks.scan', [$check, 'code' => $outside->sku]))
            ->assertSessionHas('error', __(':product is not in this round.', ['product' => 'Phone']));
        $this->actingAs($this->admin)->get(route('stock-checks.scan', [$check, 'code' => 'NOPE']))
            ->assertSessionHas('error', __('No product has the code :code.', ['code' => 'NOPE']));
    }

    /** A line is reached through its own round only. */
    public function test_a_line_belongs_to_its_round(): void
    {
        $cable = $this->product('Cable A', 1);
        $this->start();
        $first = $this->round();
        $this->start();

        $this->actingAs($this->admin)->get(route('stock-checks.line', [$this->round(), $this->lineOf($cable, $first)]))->assertNotFound();
    }

    public function test_a_finished_round_takes_no_more_answers(): void
    {
        $cable = $this->product('Cable A', 5);
        $this->start();
        $check = $this->round();
        $this->actingAs($this->admin)->post(route('stock-checks.finish', $check));

        $this->assertTrue($check->fresh()->isFinished());
        $this->actingAs($this->admin)->post(route('stock-checks.confirm', [$check, $this->lineOf($cable)]), ['seen' => 5])
            ->assertSessionHas('error', __('This stock check is finished.'));
        $this->actingAs($this->admin)->get(route('stock-checks.next', $check))->assertRedirect(route('stock-checks.show', $check));
    }

    // ---- what it says afterwards ---------------------------------------------------

    /**
     * The report: every wrong product with its difference and value — an ADJ's
     * true cost, an estimate otherwise, said so — and what was not checked.
     */
    public function test_the_report_says_what_was_wrong_and_what_was_not_checked(): void
    {
        $a = $this->product('Cable A', 5);
        $b = $this->product('Cable B', 5);
        $c = $this->product('Cable C', 5);
        $this->start();
        $check = $this->round();

        $this->actingAs($this->admin)->post(route('stock-checks.answer', [$check, $this->lineOf($a)]), ['seen' => 5, 'counted' => 3, 'fix' => 'adjusted']);
        $this->actingAs($this->admin)->post(route('stock-checks.answer', [$check, $this->lineOf($b)]), ['seen' => 5, 'counted' => 4, 'fix' => 'later']);

        $html = $this->actingAs($this->admin)->get(route('stock-checks.show', $check))->assertOk()->getContent();
        $wrong = substr($html, strpos($html, __('Wrong').' (2)'));

        $this->assertStringContainsString('Cable A', $wrong);
        $this->assertStringContainsString('ADJ-00001', $wrong);
        $this->assertStringContainsString(__('estimate'), $wrong);
        $this->assertStringContainsString(__('Not checked').' (1)', $html);
        $this->assertStringContainsString('Cable C', substr($html, strpos($html, __('Not checked').' (1)')));

        $this->actingAs($this->admin)->get(route('stock-checks.print', $check))->assertOk()->assertSee('CHK-00001');
    }

    /** A value is for a reader who may see cost. */
    public function test_values_only_for_a_reader_who_sees_cost(): void
    {
        $a = $this->product('Cable A', 5);
        $this->start();
        $this->actingAs($this->admin)->post(route('stock-checks.answer', [$this->round(), $this->lineOf($a)]), ['seen' => 5, 'counted' => 4, 'fix' => 'later']);

        $viewer = $this->staff(['stock_checks.view']);
        $viewer->forceFill(['cost_visibility' => User::COST_HIDDEN])->save();
        $this->assertFalse($viewer->seesRealCost());

        $this->actingAs($this->admin)->get(route('stock-checks.show', $this->round()))->assertSee(__('Value at cost'));

        $this->actingAs($viewer)->get(route('stock-checks.show', $this->round()))
            ->assertOk()->assertDontSee(__('Value at cost'));
    }

    /** Last checked on the product page, and the products not checked for a month. */
    public function test_last_checked_on_the_product_and_the_unchecked_filter(): void
    {
        $counted = $this->product('Cable A', 5);
        $forgotten = $this->product('Cable B', 5);
        $this->start();
        $this->actingAs($this->admin)->post(route('stock-checks.confirm', [$this->round(), $this->lineOf($counted)]), ['seen' => 5]);

        $this->actingAs($this->admin)->get(route('products.show', $counted))
            ->assertSee(__('confirmed'))->assertSee('CHK-00001');
        $this->actingAs($this->admin)->get(route('products.show', $forgotten))
            ->assertSee(__('Never checked in a stock check.'));

        $list = $this->actingAs($this->admin)->get(route('products.index', ['unchecked' => 1]))->getContent();
        $rows = substr($list, strpos($list, '<tbody>'));
        $this->assertStringContainsString('Cable B', $rows);
        $this->assertStringNotContainsString('Cable A', $rows);

        // A month later it is due again.
        $this->travel(31)->days();
        $rows = substr($list = $this->actingAs($this->admin)->get(route('products.index', ['unchecked' => 1]))->getContent(), strpos($list, '<tbody>'));
        $this->assertStringContainsString('Cable A', $rows);
    }

    // ---- who may ------------------------------------------------------------------

    public function test_seeing_and_counting_are_separate_permissions(): void
    {
        $this->product('Cable A', 5);
        $viewer = $this->staff(['stock_checks.view']);
        $nobody = $this->staff([]);

        $this->actingAs($nobody)->get(route('stock-checks.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('stock-checks.index'))->assertOk()->assertDontSee(__('Start counting'));
        $this->actingAs($viewer)->post(route('stock-checks.store'), ['scope' => 'all'])->assertForbidden();

        $this->start();
        $line = $this->round()->lines()->first();
        $this->actingAs($viewer)->post(route('stock-checks.confirm', [$this->round(), $line]), ['seen' => 5])->assertForbidden();
    }

    /** A shop already running gets the permissions by its update, which only migrates. */
    public function test_the_permissions_exist(): void
    {
        $this->assertSame(2, Permission::whereIn('key', ['stock_checks.view', 'stock_checks.count'])->count());
    }

    /** A product counted in a round is part of its record, and is not destroyed outright. */
    public function test_a_counted_product_is_held_by_its_round(): void
    {
        $cable = $this->product('Cable A', 0);
        $this->start(['with_empty' => 1]);

        $this->assertArrayHasKey('stock checks', Product::purgeBlockers([$cable->id])[$cable->id]);
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::create([
            'name' => 'Till '.count($keys), 'email' => 'till'.count($keys).'@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $user->permissions()->sync(Permission::whereIn('key', [...$keys, 'products.view'])->pluck('id')->all());

        return $user;
    }
}
