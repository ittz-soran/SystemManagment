<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DataIntegrityService;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A dollar purchase is owed in dollars — PROJECT_DOC Section 6, Soran,
 * 2026-11-02: *"i have PUR-00059 Product A 4qty at 4$ total 16$ but i pay for
 * this after 20days and curency are changed at this days"*.
 *
 * His own numbers throughout: four at $4, written at 1,460, paid twenty days
 * later at 1,500. Before this the $16 came to 24,000 against a purchase of
 * 23,360, the purchase read 640 overpaid, the supplier's balance stopped at
 * zero, and the 640 left the books without a trace.
 */
class DollarPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 10, 20, 12));
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->supplier = Supplier::create(['name' => 'Erbil Electronics']);
        $this->product = Product::create([
            'name' => 'Product A',
            'sku' => 'PA-001',
            'category_id' => Category::firstOrFail()->id,
            'unit' => 'pcs',
            'purchase_price' => 5_840,
            'sale_price' => 9_000,
        ]);
    }

    /**
     * A purchase written in dollars, the way the screen posts one: each line's
     * dinars at the rate, and the dollars typed beside them.
     *
     * @param  array<int, array{0: int, 1: int}>  $lines  [quantity, cents]
     */
    private function bought(array $lines, int $rate = 1_460, int $paid = 0, int $discount = 0): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: array_map(fn (array $l) => [
                'product_id' => $this->product->id,
                'quantity' => $l[0],
                'unit_price' => (int) round($l[1] * $rate / 100),
                'entered_currency' => 'USD',
                'entered_amount' => $l[1],
            ], $lines),
            user: $this->admin,
            purchaseDate: now(),
            discountAmount: $discount,
            amountPaid: $paid,
            exchangeRate: $rate,
        );
    }

    private function pay(Purchase $purchase, string $dollars, int $rate): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('payments.store'), [
            'payable_type' => 'purchase',
            'payable_id' => $purchase->id,
            'foreign_amount' => $dollars,
            'exchange_rate' => $rate,
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ]);
    }

    private function balance(): int
    {
        return (int) $this->supplier->fresh()->balance;
    }

    // ---- what is owed ----------------------------------------------------

    public function test_the_purchase_is_owed_in_dollars(): void
    {
        $purchase = $this->bought([[4, 400]]);

        $this->assertSame('USD', $purchase->foreign_currency);
        $this->assertSame(1_600, $purchase->foreign_total);
        $this->assertSame(23_360, $purchase->grand_total);
        $this->assertSame(1_600, $purchase->foreignDue());
        $this->assertSame(23_360, $this->balance());
    }

    /** A line nobody typed counts as the screen showed it; the discount comes off at the rate. */
    public function test_an_untyped_line_and_the_discount_count_at_the_documents_rate(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 14_600, 'entered_currency' => 'USD', 'entered_amount' => null],
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 5_840, 'entered_currency' => 'USD', 'entered_amount' => 400],
            ],
            user: $this->admin,
            purchaseDate: now(),
            discountAmount: 1_460,
            exchangeRate: 1_460,
        );

        // 2 × $10 + $4 − $1
        $this->assertSame(2_300, $purchase->foreign_total);
    }

    public function test_a_purchase_in_dinars_is_untouched(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 5_000]],
            user: $this->admin,
            purchaseDate: now(),
            amountPaid: 4_000,
        );

        $this->assertNull($purchase->foreign_currency);
        $this->assertNull($purchase->foreign_total);
        $this->assertNull($purchase->foreignDue());

        $payment = $purchase->payments()->sole();
        $this->assertNull($payment->foreign_amount);
        $this->assertSame(0, $payment->fresh()->rate_difference);
        $this->assertSame(6_000, $purchase->amountDue());
    }

    // ---- PUR-00059 -------------------------------------------------------

    public function test_pur_00059_paid_twenty_days_later_at_a_higher_rate(): void
    {
        $purchase = $this->bought([[4, 400]]);

        $this->travel(20)->days();
        $this->pay($purchase, '16', 1_500)->assertSessionHasNoErrors()->assertSessionMissing('error');

        $payment = $purchase->payments()->sole();
        $this->assertSame(24_000, $payment->amount, 'the dinars that left the till');
        $this->assertSame(1_600, $payment->foreign_amount);
        $this->assertSame(1_500, $payment->exchange_rate);
        $this->assertSame(640, $payment->rate_difference, 'the rate cost 640 more — a loss');
        $this->assertSame(Payment::DIRECTION_OUT, $payment->direction);

        $purchase->refresh();
        $this->assertSame(0, $purchase->foreignDue());
        $this->assertSame(0, $purchase->amountDue(), 'closed exactly, not 640 overpaid');
        $this->assertSame(24_000, $purchase->cashPaid());
        $this->assertSame(640, $purchase->rateDifference());
        $this->assertSame(0, $this->balance(), 'the supplier is owed nothing, and nothing vanished');
    }

    public function test_a_rate_that_fell_is_a_gain(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_400)->assertSessionMissing('error');

        $payment = $purchase->payments()->sole();
        $this->assertSame(22_400, $payment->amount);
        $this->assertSame(-960, $payment->rate_difference);
        $this->assertSame(0, $purchase->fresh()->amountDue());
        $this->assertSame(0, $this->balance());
    }

    public function test_paid_in_parts_each_at_its_own_rate(): void
    {
        $purchase = $this->bought([[4, 400]]);

        $this->pay($purchase, '10', 1_500)->assertSessionMissing('error');
        $first = $purchase->payments()->sole();
        $this->assertSame(15_000, $first->amount);
        $this->assertSame(400, $first->rate_difference, '$10 settles 14,600 of it');

        $purchase->refresh();
        $this->assertSame(600, $purchase->foreignDue());
        $this->assertSame(8_760, $purchase->amountDue());
        $this->assertSame(8_760, $this->balance());

        $this->pay($purchase, '6', 1_480)->assertSessionMissing('error');
        $second = $purchase->payments()->latest('id')->first();
        $this->assertSame(8_880, $second->amount);
        $this->assertSame(120, $second->rate_difference);

        $purchase->refresh();
        $this->assertSame(0, $purchase->foreignDue());
        $this->assertSame(0, $purchase->amountDue());
        $this->assertSame(520, $purchase->rateDifference());
        $this->assertSame(0, $this->balance());
    }

    public function test_more_than_is_owed_is_refused_in_dollars(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '10', 1_500);

        $this->pay($purchase, '6.01', 1_500)
            ->assertSessionHas('error', __(':left is left on :document.', ['left' => '6 $', 'document' => $purchase->document_no]));

        $this->assertSame(1, $purchase->payments()->count());
    }

    /**
     * ⚠️ The closing payment carries the rounding of the lines. $3.33 at 1,460
     * is 4,861.8 dinars, stored as 4,862: three of them are 14,586, and the
     * $9.99 that pays them is 14,585. Settled at the rate, a dinar would be owed
     * for ever with nothing left to pay in dollars.
     */
    public function test_the_last_dollar_closes_the_purchase_exactly_in_dinars(): void
    {
        $purchase = $this->bought([[3, 333]]);
        $this->assertSame(999, $purchase->foreign_total);
        $this->assertSame(14_586, $purchase->grand_total);

        $this->pay($purchase, '9.99', 1_460)->assertSessionMissing('error');

        $payment = $purchase->payments()->sole();
        $this->assertSame(14_585, $payment->amount);
        $this->assertSame(-1, $payment->rate_difference);
        $this->assertSame(0, $purchase->fresh()->amountDue());
        $this->assertSame(0, $this->balance());
    }

    /** A part payment never settles the last dinars while dollars are still owed. */
    public function test_a_part_payment_leaves_dinars_owing_while_dollars_are(): void
    {
        $purchase = $this->bought([[3, 333]]);
        $this->pay($purchase, '9.98', 1_460)->assertSessionMissing('error');

        $purchase->refresh();
        $this->assertSame(1, $purchase->foreignDue());
        $this->assertGreaterThan(0, $purchase->amountDue());
    }

    // ---- paid with the purchase ------------------------------------------

    public function test_paid_in_full_with_the_purchase_has_no_difference(): void
    {
        $purchase = $this->bought([[4, 400]], paid: 23_360);

        $payment = $purchase->payments()->sole();
        $this->assertSame(1_600, $payment->foreign_amount);
        $this->assertSame(1_460, $payment->exchange_rate);
        $this->assertSame(0, $payment->rate_difference);
        $this->assertSame(0, $purchase->foreignDue());
        $this->assertSame(0, $this->balance());
    }

    public function test_paid_in_part_with_the_purchase(): void
    {
        $purchase = $this->bought([[4, 400]], paid: 14_600);

        $payment = $purchase->payments()->sole();
        $this->assertSame(1_000, $payment->foreign_amount);
        $this->assertSame(0, $payment->rate_difference);
        $this->assertSame(600, $purchase->foreignDue());
        $this->assertSame(8_760, $this->balance());
    }

    /** The screen's $9.99 is 14,585 against lines of 14,586: still paid in full. */
    public function test_the_whole_dollar_total_paid_with_the_purchase_closes_it(): void
    {
        $purchase = $this->bought([[3, 333]], paid: 14_585);

        $payment = $purchase->payments()->sole();
        $this->assertSame(999, $payment->foreign_amount);
        $this->assertSame(-1, $payment->rate_difference);
        $this->assertSame(0, $purchase->amountDue());
        $this->assertSame(0, $this->balance());
    }

    // ---- correcting it ---------------------------------------------------

    public function test_editing_a_payment_settles_it_again(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_500);
        $payment = $purchase->payments()->sole();

        $this->actingAs($this->admin)->put(route('payments.update', $payment), [
            'foreign_amount' => '16',
            'exchange_rate' => 1_480,
            'payment_method' => 'bank',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $payment->refresh();
        $this->assertSame(23_680, $payment->amount);
        $this->assertSame(320, $payment->rate_difference);
        $this->assertSame(0, $purchase->fresh()->amountDue());
        $this->assertSame(0, $this->balance());
    }

    public function test_a_payment_edited_to_less_leaves_dollars_owing(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_500);
        $payment = $purchase->payments()->sole();

        $this->actingAs($this->admin)->put(route('payments.update', $payment), [
            'foreign_amount' => '10',
            'exchange_rate' => 1_500,
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertSessionMissing('error');

        $purchase->refresh();
        $this->assertSame(600, $purchase->foreignDue());
        $this->assertSame(8_760, $purchase->amountDue());
        $this->assertSame(8_760, $this->balance());
    }

    public function test_deleting_a_payment_puts_back_what_it_settled(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_500);

        $this->actingAs($this->admin)
            ->delete(route('payments.destroy', $purchase->payments()->sole()))
            ->assertSessionMissing('error');

        $purchase->refresh();
        $this->assertSame(1_600, $purchase->foreignDue());
        $this->assertSame(23_360, $purchase->amountDue());
        $this->assertSame(23_360, $this->balance(), 'not 24,000 — that was never owed');
        $this->assertSame(0, $purchase->rateDifference());
    }

    /**
     * Edit the rate of a purchase already paid in full, and its dinars move
     * while its dollars do not. The payment settles again, and it stays closed.
     */
    public function test_editing_the_purchase_settles_its_payments_again(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_500);

        app(PurchaseService::class)->update(
            purchase: $purchase->fresh(),
            supplier: $this->supplier,
            lines: [[
                'product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 5_880,
                'entered_currency' => 'USD', 'entered_amount' => 400,
            ]],
            user: $this->admin,
            purchaseDate: now(),
            exchangeRate: 1_470,
        );

        $purchase->refresh();
        $this->assertSame(23_520, $purchase->grand_total);
        $this->assertSame(0, $purchase->foreignDue());
        $this->assertSame(0, $purchase->amountDue());
        $this->assertSame(480, $purchase->payments()->sole()->rate_difference);
        $this->assertSame(0, $this->balance());
    }

    public function test_an_edit_cannot_take_the_dollar_total_below_what_was_paid(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_500);

        $this->expectExceptionMessage(__('The new total of :total is less than the :paid already paid. Record a refund first.', [
            'total' => '12 $', 'paid' => '16 $',
        ]));

        app(PurchaseService::class)->update(
            purchase: $purchase->fresh(),
            supplier: $this->supplier,
            lines: [[
                'product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 5_840,
                'entered_currency' => 'USD', 'entered_amount' => 400,
            ]],
            user: $this->admin,
            purchaseDate: now(),
            exchangeRate: 1_460,
        );
    }

    // ---- the profit report ----------------------------------------------

    /** @return array<string, int> */
    private function profit(string $from, string $to): array
    {
        return $this->actingAs($this->admin)
            ->get(route('reports.index', ['from' => $from, 'to' => $to]))
            ->assertOk()
            ->viewData('profit');
    }

    /**
     * The 640 is one line, in the period the payment was made — not the
     * purchase's, and not the cost of the goods.
     */
    public function test_the_difference_is_a_line_on_the_profit_report_when_it_was_paid(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $bought = now()->toDateString();

        $this->travel(20)->days();
        $this->pay($purchase, '16', 1_500);
        $paid = now()->toDateString();

        $then = $this->profit($bought, $bought);
        $this->assertSame(0, $then['rate_difference'], 'nothing on the day it was bought');

        $now = $this->profit($paid, $paid);
        $this->assertSame(640, $now['rate_difference']);
        $this->assertSame(-640, $now['net'], 'a loss comes off the net');
        $this->assertSame(0, $now['cogs'], 'and not off the cost of anything sold');

        $page = $this->actingAs($this->admin)
            ->get(route('reports.index', ['from' => $paid, 'to' => $paid]))->getContent();
        $this->assertSame(1, preg_match('/<td>'.preg_quote(__('Dollar rate difference'), '/').'<\/td>\s*<td class="money[^"]*">\s*([^<]+?)\s*<\/td>/', $page, $cell));
        $this->assertSame('640', $cell[1]);
    }

    public function test_a_gain_adds_to_the_net(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_400);

        $profit = $this->profit(now()->toDateString(), now()->toDateString());
        $this->assertSame(-960, $profit['rate_difference']);
        $this->assertSame(960, $profit['net']);
    }

    /** A shop that never buys in dollars sees no new line at all. */
    public function test_no_line_when_nothing_was_paid_at_another_rate(): void
    {
        $page = $this->actingAs($this->admin)->get(route('reports.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(__('Dollar rate difference'), $page);
    }

    // ---- returns -----------------------------------------------------------

    private function sendBack(Purchase $purchase, int $quantity): void
    {
        app(PurchaseReturnService::class)->create(
            purchase: $purchase->fresh(),
            lines: [['purchase_item_id' => $purchase->items()->first()->id, 'quantity' => $quantity]],
            user: $this->admin,
            returnDate: now(),
        );
    }

    public function test_a_return_takes_its_dollars_off_what_is_owed(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->sendBack($purchase, 1);

        $this->assertSame(1_200, $purchase->fresh()->foreignDue());

        $this->pay($purchase, '12', 1_500)->assertSessionMissing('error');

        $purchase->refresh();
        $this->assertSame(0, $purchase->foreignDue());
        $this->assertSame(0, $purchase->amountDue());
        $this->assertSame(480, $purchase->rateDifference());
        $this->assertSame(0, $this->balance());
    }

    /** Everything sent back leaves nothing owing — not a cent of rounding. */
    public function test_a_return_of_everything_leaves_no_dollars_owing(): void
    {
        $purchase = $this->bought([[3, 333]]);
        $this->sendBack($purchase, 3);

        $purchase->refresh();
        $this->assertSame(0, $purchase->amountDue());
        $this->assertSame(0, $purchase->foreignDue());
    }

    // ---- what was written before -----------------------------------------

    /**
     * Run the migration's backfill on purchases written the old way: the dollar
     * total from the lines, payments at the purchase's own rate, no difference.
     */
    public function test_purchases_written_before_get_their_dollars_from_their_lines(): void
    {
        $paidUp = $this->bought([[3, 333]]);
        $open = $this->bought([[4, 400]]);

        // Two payments that together paid the first exactly, and one on the second.
        foreach ([[$paidUp, 7_293], [$paidUp, 7_293], [$open, 14_600]] as [$purchase, $amount]) {
            Payment::create([
                'document_no' => 'PAY-OLD-'.$purchase->id.'-'.Payment::count(),
                'payable_type' => $purchase->getMorphClass(),
                'payable_id' => $purchase->id,
                'amount' => $amount,
                'direction' => Payment::DIRECTION_OUT,
                'payment_method' => 'cash',
                'paid_at' => now(),
                'user_id' => $this->admin->id,
            ]);
        }

        DB::table('purchases')->update(['foreign_currency' => null, 'foreign_total' => null]);
        DB::table('payments')->update(['foreign_amount' => null, 'exchange_rate' => null]);

        $migration = require database_path('migrations/2026_01_01_000064_owe_a_dollar_purchase_in_dollars.php');
        (fn () => $this->backfill())->call($migration);

        $paidUp->refresh();
        $this->assertSame('USD', $paidUp->foreign_currency);
        $this->assertSame(999, $paidUp->foreign_total);
        $this->assertSame([500, 499], $paidUp->payments()->orderBy('id')->pluck('foreign_amount')->all(),
            'the last payment takes the cent, and the purchase is paid');
        $this->assertSame(0, $paidUp->foreignDue());
        $this->assertSame(0, $paidUp->rateDifference());

        $open->refresh();
        $this->assertSame(1_600, $open->foreign_total);
        $this->assertSame(1_000, $open->payments()->sole()->foreign_amount);
        $this->assertSame(1_460, $open->payments()->sole()->exchange_rate);
        $this->assertSame(600, $open->foreignDue());
    }

    public function test_the_backfill_leaves_a_purchase_in_dinars_alone(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 5_000]],
            user: $this->admin,
            purchaseDate: now(),
            amountPaid: 5_000,
        );

        $migration = require database_path('migrations/2026_01_01_000064_owe_a_dollar_purchase_in_dollars.php');
        (fn () => $this->backfill())->call($migration);

        $this->assertNull($purchase->fresh()->foreign_total);
        $this->assertNull($purchase->payments()->sole()->foreign_amount);
    }

    // ---- Check the data -------------------------------------------------

    /** @return array<string, mixed> */
    private function overpaidCheck(): array
    {
        return collect(app(DataIntegrityService::class)->run()['checks'])->firstWhere('key', 'overpaid');
    }

    /** 24,000 against 23,360 is paid exactly: the 640 is the rate. */
    public function test_a_dollar_purchase_paid_at_a_higher_rate_is_not_overpaid(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_500);

        $this->assertSame(0, $this->overpaidCheck()['failed']);
    }

    /** ⚠️ It used to read a purchase's money out as negative and flag none. */
    public function test_the_check_now_catches_an_overpaid_purchase(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 5_000]],
            user: $this->admin,
            purchaseDate: now(),
            amountPaid: 5_000,
        );

        DB::table('payments')->where('payable_id', $purchase->id)
            ->where('payable_type', $purchase->getMorphClass())->update(['amount' => 9_000]);

        $check = $this->overpaidCheck();
        $this->assertSame(1, $check['failed']);
        $this->assertStringContainsString($purchase->document_no, $check['examples'][0]['what']);
    }

    // ---- the screens -----------------------------------------------------

    public function test_the_payment_screen_takes_dollars_at_todays_rate(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '10', 1_500);

        $page = $this->actingAs($this->admin)
            ->get(route('payments.create', ['payable_type' => 'purchase', 'payable_id' => $purchase->id]))
            ->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<input id="foreign_amount"[^>]*value="([^"]*)"/', $page, $amount));
        $this->assertSame('6', $amount[1], 'the box starts at what is still owed, in dollars');
        $this->assertSame(1, preg_match('/<input id="exchange_rate"[^>]*value="([^"]*)"/', $page, $rate));
        $this->assertSame('1320', $rate[1], 'and at the rate on the currencies page');
        $this->assertSame(0, preg_match('/<select id="direction"/', $page), 'a dollar purchase is paid with money out only');
        $this->assertSame(0, preg_match('/name="amount"/', $page), 'no dinar box beside the dollar one');
    }

    public function test_the_purchase_page_says_what_is_owed_in_dollars(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '10', 1_500);

        $page = $this->actingAs($this->admin)->get(route('purchases.show', $purchase))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<span>'.preg_quote(__('Due'), '/').'<\/span>\s*<span class="money">\s*([^<]+?)\s*<\/span>/', $page, $due));
        $this->assertSame('6 $', $due[1]);
        // $6 at the currencies page's 1,320.
        $this->assertSame(1, preg_match('/≈ ([0-9,]+) IQD/', $page, $estimate));
        $this->assertSame('7,920', $estimate[1]);
        $this->assertSame(1, preg_match('/<span>'.preg_quote(__('Dollar rate difference'), '/').'<\/span>\s*<span class="money">([^<]+)<\/span>/', $page, $cost));
        $this->assertSame('−400', $cost[1]);
    }

    public function test_the_payment_page_says_what_the_rate_cost(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_500);

        $this->actingAs($this->admin)->get(route('payments.show', $purchase->payments()->sole()))
            ->assertOk()
            ->assertSeeText(__(':amount more than the purchase cost — a loss', ['amount' => '640 IQD']));
    }

    public function test_the_edit_screen_opens_in_dollars_as_it_was_paid(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $this->pay($purchase, '16', 1_500);

        $page = $this->actingAs($this->admin)->get(route('payments.edit', $purchase->payments()->sole()))
            ->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<input id="foreign_amount"[^>]*value="([^"]*)"/', $page, $amount));
        $this->assertSame('16', $amount[1]);
        $this->assertSame(1, preg_match('/<input id="exchange_rate"[^>]*value="([^"]*)"/', $page, $rate));
        $this->assertSame('1500', $rate[1]);
    }

    // ---- PUR-00059 as it is in the shop now ------------------------------

    /**
     * Paid the old way: 24,000 dinars against a 23,360 purchase, posted whole,
     * then given its dollars by the migration as everything written before is.
     */
    private function paidTheOldWay(Purchase $purchase, int $amount): Payment
    {
        $payment = DB::transaction(function () use ($purchase, $amount) {
            $payment = app(PaymentService::class)->record(
                payable: $purchase, amount: $amount, direction: Payment::DIRECTION_OUT, user: $this->admin,
            );
            app(LedgerService::class)->post(
                account: $this->supplier->fresh(), type: AccountTransaction::TYPE_PAYMENT,
                amount: -$amount, reference: $purchase, user: $this->admin,
            );

            return $payment;
        });

        DB::table('payments')->update(['foreign_amount' => null, 'exchange_rate' => null]);
        $migration = require database_path('migrations/2026_01_01_000064_owe_a_dollar_purchase_in_dollars.php');
        (fn () => $this->backfill())->call($migration);

        return $payment->fresh();
    }

    private function correct(Payment $payment, string $dollars, int $rate): void
    {
        $this->actingAs($this->admin)->put(route('payments.update', $payment), [
            'foreign_amount' => $dollars,
            'exchange_rate' => $rate,
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
    }

    /**
     * ⚠️ The supplier was owed nothing else, so the old 24,000 only ever
     * applied 23,360 — the balance stops at zero. Putting the payment's own
     * figure back would leave him owed 640 that nobody owes.
     */
    public function test_pur_00059_corrected_when_the_supplier_was_owed_nothing_else(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $payment = $this->paidTheOldWay($purchase, 24_000);

        $this->assertSame(0, $this->balance());
        $this->assertSame(-44, $purchase->fresh()->foreignDue(), 'read as $0.44 overpaid until corrected');

        $this->correct($payment, '16', 1_500);

        $payment->refresh();
        $this->assertSame(24_000, $payment->amount);
        $this->assertSame(640, $payment->rate_difference);
        $this->assertSame(0, $purchase->fresh()->foreignDue());
        $this->assertSame(0, $purchase->fresh()->amountDue());
        $this->assertSame(0, $this->balance(), 'not 640');
    }

    /** Owed on another purchase too, the old 24,000 took 640 off THAT one. */
    public function test_pur_00059_corrected_when_the_supplier_was_owed_more(): void
    {
        $other = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100_000]],
            user: $this->admin,
            purchaseDate: now(),
        );
        $purchase = $this->bought([[4, 400]]);
        $payment = $this->paidTheOldWay($purchase, 24_000);

        $this->assertSame(99_360, $this->balance(), 'the 640 came off the other purchase');

        $this->correct($payment, '16', 1_500);

        $this->assertSame(100_000, $this->balance(), 'exactly what the other purchase still owes');
        $this->assertSame(100_000, $other->fresh()->amountDue());
    }

    public function test_deleting_an_old_payment_that_went_past_zero(): void
    {
        $purchase = $this->bought([[4, 400]]);
        $payment = $this->paidTheOldWay($purchase, 24_000);

        $this->actingAs($this->admin)->delete(route('payments.destroy', $payment))->assertSessionMissing('error');

        $this->assertSame(23_360, $this->balance(), 'what the purchase is owed, not 24,000');
        $this->assertSame(1_600, $purchase->fresh()->foreignDue());
    }
}
