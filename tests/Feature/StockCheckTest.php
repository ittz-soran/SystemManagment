<?php

namespace Tests\Feature;

use App\Http\Controllers\StockAdjustmentController;
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
use App\Services\ActivityLogger;
use App\Services\NotificationFeed;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockCheckService;
use App\Services\TransferService;
use App\Support\Navigation;
use App\Support\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
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
        // One of the column's five reasons — MariaDB refuses any other word.
        $this->assertSame('miscount', $adjustment->reason);
        $this->assertContains($adjustment->reason, StockAdjustmentController::REASONS);
        $this->assertSame(__('Stock check :number', ['number' => 'CHK-00001']).' — two gone', $adjustment->notes);
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
        $this->assertSame(3, Permission::whereIn('key', ['stock_checks.view', 'stock_checks.count', 'stock_checks.assign'])->count());
    }

    /** A product counted in a round is part of its record, and is not destroyed outright. */
    public function test_a_counted_product_is_held_by_its_round(): void
    {
        $cable = $this->product('Cable A', 0);
        $this->start(['with_empty' => 1]);

        $this->assertArrayHasKey('stock checks', Product::purgeBlockers([$cable->id])[$cable->id]);
    }

    // ---- sent to someone (Soran, 2026-10-30) -----------------------------------

    /** "i send check request by blend count and set properties -> user start" — told by the bell, the phone and the dashboard. */
    public function test_a_round_sent_to_someone_tells_them_and_nobody_else(): void
    {
        $this->product('Cable A', 4);
        $hawkar = $this->person('Hawkar', ['stock_checks.count', 'dashboard.view']);
        $aram = $this->person('Aram', ['stock_checks.view', 'stock_checks.count', 'products.view']);

        $check = $this->send($hawkar, ['scope' => 'category', 'category_id' => $this->cables->id, 'blind' => 1]);

        $this->assertTrue($check->isSentTo($hawkar));
        $this->assertTrue($check->blind);

        // The bell, and the phone, which reads the same list: Hawkar only.
        $told = app(NotificationFeed::class)->visible($hawkar)->get();
        $this->assertCount(1, $told);
        $this->assertSame(Notifications::ALERT, $told->first()->tier);
        $this->assertSame(__(':name sent you stock check :number: :scope, blind count', ['name' => $this->admin->name, 'number' => 'CHK-00001', 'scope' => 'Cables']), $told->first()->description);
        $this->assertSame(route('stock-checks.show', $check), Notifications::linkFor($told->first()->module, $told->first()->action, $told->first()->record_id));
        $this->assertFalse(app(NotificationFeed::class)->visible($aram)->where('module', 'stock_checks')->exists());
        $this->assertFalse(app(NotificationFeed::class)->visible($this->admin)->where('module', 'stock_checks')->exists());

        // ⚠️ Addressed means to that person only, whatever the module — even
        // one Aram may hear about.
        $private = app(ActivityLogger::class)->log(action: 'update', module: 'products', description: 'For Hawkar only', user: $this->admin, recipient: $hawkar);
        $this->assertTrue(app(NotificationFeed::class)->visible($hawkar)->whereKey($private->id)->exists());
        $this->assertFalse(app(NotificationFeed::class)->visible($aram)->whereKey($private->id)->exists());

        // And the dashboard, until it is finished.
        $card = $this->actingAs($hawkar)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-sent-to-me', $card);
        $this->assertStringContainsString('CHK-00001', $card);
        $this->assertStringContainsString(route('stock-checks.next', $check), $card);
        $this->actingAs($aram)->get(route('dashboard'))->assertDontSee('data-sent-to-me', false);

        app(StockCheckService::class)->finish($check, $hawkar);
        $this->actingAs($hawkar)->get(route('dashboard'))->assertDontSee('data-sent-to-me', false);
    }

    /** ⚠️ One person per round: the one it was sent to answers, nobody else — not even the admin. */
    public function test_only_the_person_it_was_sent_to_answers(): void
    {
        $cable = $this->product('Cable A', 4);
        $hawkar = $this->person('Hawkar', ['stock_checks.count']);
        $aram = $this->person('Aram', ['stock_checks.view', 'stock_checks.count']);
        $check = $this->send($hawkar);
        $line = $this->lineOf($cable);

        foreach ([$this->admin, $aram] as $somebodyElse) {
            $this->actingAs($somebodyElse)->get(route('stock-checks.line', [$check, $line]))->assertForbidden();
            $this->actingAs($somebodyElse)->post(route('stock-checks.confirm', [$check, $line]), ['seen' => 4])->assertForbidden();
            $this->actingAs($somebodyElse)->post(route('stock-checks.skip', [$check, $line]))->assertForbidden();
            $this->actingAs($somebodyElse)->post(route('stock-checks.answer', [$check, $line]), ['seen' => 4, 'counted' => 3, 'fix' => 'later'])->assertForbidden();
        }

        // The service holds the same line, whatever reaches it.
        try {
            app(StockCheckService::class)->confirm($line, 4, $this->admin);
            $this->fail('The admin answered in a round sent to Hawkar.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('This stock check was sent to :name, and only they answer in it.', ['name' => 'Hawkar']), $e->getMessage());
        }
        $this->assertSame(StockCheckLine::PENDING, $line->fresh()->status);

        $this->actingAs($hawkar)->post(route('stock-checks.confirm', [$check, $line]), ['seen' => 4])->assertRedirect(route('stock-checks.next', $check));
        $this->assertSame(StockCheckLine::CONFIRMED, $line->fresh()->status);
        $this->assertSame($hawkar->id, $line->fresh()->checked_by);
    }

    /** "results show by both user and admin": the counter needs only `count`, and sees only what was sent to them. */
    public function test_the_counter_sees_their_round_and_nothing_else(): void
    {
        $this->product('Cable A', 4);
        $hawkar = $this->person('Hawkar', ['stock_checks.count']);
        $theirs = $this->send($hawkar);
        $this->start();
        $others = $this->round();

        $this->assertContains('stock-checks.index', array_column(Navigation::pagesFor($hawkar), 'route'));
        $list = $this->actingAs($hawkar)->get(route('stock-checks.index'))->assertOk();
        $list->assertSee($theirs->document_no)->assertDontSee($others->document_no)->assertDontSee(route('stock-checks.to-fix'));
        $list->assertDontSee('name="scope"', false);

        // No previous and next for them: those lead into other people's rounds.
        $this->actingAs($hawkar)->get(route('stock-checks.show', $theirs))->assertOk()->assertSee(__('Start counting'))
            ->assertDontSee('aria-label="'.__('Previous and next').'"', false);
        $this->actingAs($this->admin)->get(route('stock-checks.show', $theirs))->assertSee('aria-label="'.__('Previous and next').'"', false);
        $this->actingAs($hawkar)->get(route('stock-checks.show', $others))->assertForbidden();
        $this->actingAs($hawkar)->get(route('stock-checks.print', $others))->assertForbidden();
        $this->actingAs($hawkar)->get(route('stock-checks.to-fix'))->assertForbidden();
        $this->actingAs($hawkar)->get(route('stock-checks.next', $others))->assertForbidden();
        $this->actingAs($hawkar)->get(route('stock-checks.next', $theirs))->assertRedirect();

        // The sender watches it, without counting in it.
        $page = $this->actingAs($this->admin)->get(route('stock-checks.show', $theirs))->assertOk();
        $page->assertSee(__('sent :date by :sender to :name', ['date' => now()->format(setting('date_format', 'Y-m-d')), 'sender' => $this->admin->name, 'name' => 'Hawkar']));
        $page->assertDontSee(route('stock-checks.next', $theirs))->assertSee(route('stock-checks.finish', $theirs));
        $this->actingAs($this->admin)->get(route('stock-checks.index'))->assertSee(__('Waiting for :name', ['name' => 'Hawkar']));
    }

    /** "Count only, admin fixes": no ADJ from a sent round, even for a counter who could make one. */
    public function test_a_sent_round_counts_only_and_the_admin_fixes(): void
    {
        $cable = $this->product('Cable A', 5);
        $hawkar = $this->person('Hawkar', ['stock_checks.count', 'stock_adjustments.create']);
        $check = $this->send($hawkar);
        $line = $this->lineOf($cable);

        $this->actingAs($hawkar)->get(route('stock-checks.line', [$check, $line]))->assertOk()
            ->assertDontSee('id="fix-adjusted"', false)
            ->assertSee(__('A wrong count goes on the To fix list, and :name puts it right.', ['name' => $this->admin->name]));

        // Whatever the form says.
        $this->actingAs($hawkar)->post(route('stock-checks.answer', [$check, $line]), ['seen' => 5, 'counted' => 3, 'fix' => 'adjusted'])
            ->assertRedirect(route('stock-checks.next', $check));
        $this->assertSame([StockCheckLine::WRONG, StockCheckLine::FIX_LATER, null], [$line->fresh()->status, $line->fresh()->fix, $line->fresh()->stock_adjustment_id]);
        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame(5, $this->held($cable));

        // The admin puts it right from To fix.
        $this->actingAs($this->admin)->post(route('stock-checks.adjust', $line))->assertSessionHas('success');
        $this->assertSame(3, $this->held($cable));
    }

    /** Sending: by someone allowed to send, to someone else who may count. */
    public function test_who_may_send_and_to_whom(): void
    {
        $this->product('Cable A', 4);
        $hawkar = $this->person('Hawkar', ['stock_checks.count']);
        $aram = $this->person('Aram', ['stock_checks.view', 'stock_checks.count']);
        $cashier = $this->person('Cashier', ['sales.create']);
        $gone = $this->person('Gone', ['stock_checks.count']);
        $gone->forceFill(['is_active' => false])->save();

        // Aram may start a round, but not send one.
        $this->actingAs($aram)->get(route('stock-checks.index'))->assertOk()->assertDontSee('name="assigned_to"', false);
        $this->actingAs($aram)->post(route('stock-checks.store'), ['scope' => 'all', 'assigned_to' => $hawkar->id])
            ->assertSessionHas('error', __('Sending a stock check to someone needs the permission to send stock checks.'));

        $this->actingAs($this->admin)->post(route('stock-checks.store'), ['scope' => 'all', 'assigned_to' => $this->admin->id])
            ->assertSessionHas('error', __('Send it to someone else, or leave it to anyone.'));
        foreach ([$cashier, $gone] as $cannot) {
            $this->actingAs($this->admin)->post(route('stock-checks.store'), ['scope' => 'all', 'assigned_to' => $cannot->id])
                ->assertSessionHas('error', __(':name may not count stock. Give them the permission first.', ['name' => $cannot->name]));
        }
        $this->assertSame(0, StockCheck::count());

        // The box offers only who it can be sent to — read from the box itself:
        // a category and a room on the same page have ids too.
        $page = $this->actingAs($this->admin)->get(route('stock-checks.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<select id="assigned_to".*?<\/select>/s', $page, $box));
        $this->assertStringContainsString('<option value="'.$hawkar->id.'"', $box[0]);
        $this->assertStringContainsString('<option value="'.$aram->id.'"', $box[0]);
        foreach ([$this->admin, $cashier, $gone] as $not) {
            $this->assertStringNotContainsString('<option value="'.$not->id.'"', $box[0]);
        }
    }

    /** Finishing tells the other of the two: the sender what it found, the counter that it was stopped. */
    public function test_finishing_tells_the_other_one(): void
    {
        $cable = $this->product('Cable A', 5);
        $this->product('Cable B', 2);
        $hawkar = $this->person('Hawkar', ['stock_checks.count']);
        $aram = $this->person('Aram', ['stock_checks.view', 'stock_checks.count']);

        $check = $this->send($hawkar);
        $this->actingAs($hawkar)->post(route('stock-checks.answer', [$check, $this->lineOf($cable)]), ['seen' => 5, 'counted' => 4, 'fix' => 'later']);

        $this->actingAs($aram)->post(route('stock-checks.finish', $check))
            ->assertSessionHas('error', __('Only the person it was sent to, or the person who sent it, can finish this stock check.'));
        $this->assertFalse($check->fresh()->isFinished());

        $this->actingAs($hawkar)->post(route('stock-checks.finish', $check))->assertRedirect(route('stock-checks.show', $check));
        $told = app(NotificationFeed::class)->visible($this->admin)->where('module', 'stock_checks')->get();
        $this->assertCount(1, $told);
        $this->assertSame(__(':name finished stock check :number: :confirmed confirmed, :wrong wrong, :unchecked not checked', [
            'name' => 'Hawkar', 'number' => 'CHK-00001', 'confirmed' => 0, 'wrong' => 1, 'unchecked' => 1,
        ]), $told->first()->description);

        // Stopped early by the sender: the counter is told.
        $second = $this->send($hawkar);
        $this->actingAs($this->admin)->post(route('stock-checks.finish', $second))->assertRedirect(route('stock-checks.show', $second));
        $this->assertTrue($second->fresh()->isFinished());
        $this->assertSame(1, app(NotificationFeed::class)->visible($hawkar)->where('module', 'stock_checks')->where('record_id', $second->id)->where('action', 'update')->count());
    }

    /** ⚠️ The figure shows after one count (his choice), so the first count is kept and the report sets it beside the answer. */
    public function test_the_first_blind_count_is_kept_and_reported(): void
    {
        $cable = $this->product('Cable A', 4);
        $hawkar = $this->person('Hawkar', ['stock_checks.count']);
        $check = $this->send($hawkar, ['blind' => 1]);
        $line = $this->lineOf($cable);

        $this->actingAs($hawkar)->post(route('stock-checks.count', [$check, $line]), ['seen' => 4, 'counted' => 3])
            ->assertSessionHas('counted', 3);
        $this->assertSame(3, $line->fresh()->first_count);

        // A reload shows the figure and the count, not a blind box again —
        // the second load, once the count carried by the redirect is spent.
        $this->actingAs($hawkar)->get(route('stock-checks.line', [$check, $line]))->assertSee('data-system', false);
        $this->actingAs($hawkar)->get(route('stock-checks.line', [$check, $line]))
            ->assertSee('data-system', false)->assertSee('value="3"', false)->assertDontSee('id="blind-count"', false);

        // Only the first is kept.
        $this->actingAs($hawkar)->post(route('stock-checks.count', [$check, $line]), ['seen' => 4, 'counted' => 2]);
        $this->assertSame(3, $line->fresh()->first_count);

        // Recounted, and it agrees: a confirm — and the report says how it got there.
        $this->actingAs($hawkar)->post(route('stock-checks.answer', [$check, $line]), ['seen' => 4, 'counted' => 4, 'fix' => 'later']);
        $this->assertSame(StockCheckLine::CONFIRMED, $line->fresh()->status);

        $html = $this->actingAs($this->admin)->get(route('stock-checks.show', $check))->assertOk()->getContent();
        $recounted = substr($html, strpos($html, 'data-recounted'));
        $this->assertStringContainsString(__('Counted again').' (1)', $recounted);
        $this->assertStringContainsString('Cable A', $recounted);
        $this->assertMatchesRegularExpression('/data-first-count>\s*3 pcs/', $recounted);

        // A recount that says the same as the first count is not "counted
        // again" — the counter stood by it.
        $kept = $this->product('Cable C', 4);
        $third = $this->send($hawkar, ['blind' => 1]);
        $this->actingAs($hawkar)->post(route('stock-checks.count', [$third, $this->lineOf($kept, $third)]), ['seen' => 4, 'counted' => 3]);
        $this->actingAs($hawkar)->post(route('stock-checks.answer', [$third, $this->lineOf($kept, $third)]), ['seen' => 4, 'counted' => 3, 'fix' => 'later']);
        $this->assertSame([3, 3], [$this->lineOf($kept, $third)->first_count, $this->lineOf($kept, $third)->counted]);
        $this->actingAs($this->admin)->get(route('stock-checks.show', $third))->assertDontSee('data-recounted', false);

        // A count that agreed the first time is not "counted again".
        $other = $this->product('Cable B', 2);
        $second = $this->send($hawkar, ['blind' => 1]);
        $this->actingAs($hawkar)->post(route('stock-checks.count', [$second, $this->lineOf($other, $second)]), ['seen' => 2, 'counted' => 2]);
        $this->assertNull($this->lineOf($other, $second)->first_count);
        $this->actingAs($this->admin)->get(route('stock-checks.show', $second))->assertDontSee('data-recounted', false);
    }

    /** ⚠️ A skipped product is still unanswered: a blind round keeps its figure hidden when it comes back. */
    public function test_a_skipped_product_stays_blind(): void
    {
        $cable = $this->product('Cable A', 4);
        $this->start(['blind' => 1]);
        $line = $this->lineOf($cable);

        $this->actingAs($this->admin)->post(route('stock-checks.skip', [$this->round(), $line]));
        $this->assertSame(StockCheckLine::SKIPPED, $line->fresh()->status);

        $this->actingAs($this->admin)->get(route('stock-checks.line', [$this->round(), $line]))
            ->assertOk()->assertDontSee('data-system', false)->assertSee('id="blind-count"', false);
    }

    /** "are i type 4 and click on save … create new adj or ....?" — the screen says a matching count is a confirm. */
    public function test_the_save_button_says_confirm_when_the_count_agrees(): void
    {
        $cable = $this->product('Cable A', 4);
        $this->start();

        $page = $this->actingAs($this->admin)->get(route('stock-checks.line', [$this->round(), $this->lineOf($cable)]))->assertOk();
        $page->assertSee('data-agrees="'.e(__('Confirm — the shelf agrees')).'"', false);
        $page->assertSee('data-when-different', false);

        // Rows of 32.5px round to a pixel more than their box; Windows drew a
        // scrollbar with arrows for it (his screenshot). Sideways only.
        $this->assertSame(2, substr_count($page->getContent(), 'class="table-responsive overflow-y-hidden"'));
        $this->assertSame(0, substr_count($page->getContent(), 'class="table-responsive"'));
    }

    /** @param  list<string>  $keys */
    private function person(string $name, array $keys): User
    {
        $user = User::create([
            'name' => $name, 'email' => strtolower($name).'@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $user->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id')->all());

        return $user;
    }

    /** Sent by the admin, as Soran would. */
    private function send(User $to, array $form = []): StockCheck
    {
        $this->actingAs($this->admin)->post(route('stock-checks.store'), ['scope' => 'all', 'assigned_to' => $to->id, ...$form])
            ->assertSessionMissing('error');

        return $this->round();
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
