<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Repair;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\RepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * A part the shop has not got, bought for the job — Soran, 2026-09-23.
 *
 * *"some times repair person change screen for customer but new screen is not
 * in stock or rooms, just when start the job buy new screen somewhere and start
 * replacement"*.
 *
 * ⚠️ **What this exists to stop is the worst moment in the module.** Before it,
 * the line went on, the customer accepted, the ticket printed — and collection
 * was refused, `Not enough stock: 0 available`, with the mended phone on the
 * counter and the customer's hand out. The first test here is that failure,
 * kept as the thing being prevented.
 *
 * ⚠️ And it is a PURCHASE, not a cost written on a line. Section 5 gets one
 * costing path; a cost the books never saw would be a second.
 */
class RepairBuyPartTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile', 'phone' => '0770', 'is_active' => true]);
    }

    private function user(): User
    {
        return User::first();
    }

    private function bench(bool $mayBuy = true, string $email = 'rebin@example.com'): User
    {
        $user = User::create([
            'name' => 'Rebin Aziz', 'email' => $email,
            'password' => 'x', 'role' => User::ROLE_USER, 'is_active' => true,
        ]);

        $keys = ['auth.login', 'repairs.view', 'repairs.create', 'repairs.edit'];

        if ($mayBuy) {
            $keys[] = 'repairs.buy_part';
        }

        $user->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id'));

        return $user;
    }

    /** A part nobody has ever bought: no batches, nothing on the shelf. */
    private function unstocked(): Product
    {
        return Product::create([
            'name' => 'Xiaomi 13 screen', 'kind' => Product::KIND_STOCK,
            'sku' => 'XIA-SCR', 'barcode' => 'XIA-SCR-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 0, 'quantity' => 0,
        ]);
    }

    /**
     * ⚠️ The failure this feature exists to prevent, kept as a test.
     *
     * The job is taken in and accepted — the customer is holding a printed
     * ticket — and only then does the shop discover it cannot hand the device
     * over. If this ever starts passing, something has quietly begun selling
     * stock the shop has not got.
     */
    public function test_without_buying_it_the_job_cannot_be_collected_at_all(): void
    {
        $part = $this->unstocked();

        $repair = app(RepairService::class)->create(
            customer: $this->customer, device: 'Xiaomi 13', fault: 'Screen smashed',
            user: $this->user(),
            lines: [['product_id' => $part->id, 'quantity' => 1, 'unit_price' => 45_000]],
        );

        app(RepairService::class)->accept($repair, $this->user());

        $this->expectException(InsufficientStockException::class);

        app(RepairService::class)->collect($repair->fresh('items'), $this->user());
    }

    /** Buying it makes a real purchase, a real batch, and lets the job finish. */
    public function test_buying_the_part_opens_a_batch_and_the_job_collects(): void
    {
        $bought = app(RepairService::class)->buyPart(
            name: 'Xiaomi 13 screen', supplier: $this->supplier, quantity: 1,
            unitCost: 38_000, salePrice: 45_000, warrantyDays: 5, user: $this->user(),
        );

        $part = $bought['product'];

        $this->assertInstanceOf(Purchase::class, $bought['purchase']);
        $this->assertSame(1, $part->fresh()->quantity, 'nothing went on the shelf');
        $this->assertSame(38_000, (int) StockBatch::where('product_id', $part->id)->value('unit_cost'));
        $this->assertSame(5, $part->fresh()->warranty_days, 'the warranty was dropped on the way in');

        // ⚠️ Paid, in full, in cash — the repair person paid at the counter of
        // the shop down the street, and a debt would be a lie.
        $this->assertSame(0, (int) $this->supplier->fresh()->balance, 'the shop is shown as owing for it');

        $repair = app(RepairService::class)->create(
            customer: $this->customer, device: 'Xiaomi 13', fault: 'Screen smashed',
            user: $this->user(),
            lines: [['product_id' => $part->id, 'quantity' => 1, 'unit_price' => 45_000]],
        );

        app(RepairService::class)->accept($repair, $this->user());
        $sale = app(RepairService::class)->collect($repair->fresh('items'), $this->user());

        $this->assertSame(Repair::STATUS_COLLECTED, $repair->fresh()->status);

        // ⚠️ And the cost the job reports is the batch the purchase opened —
        // the same row Profit & Loss reads, not the price typed on the line.
        $cost = app(RepairService::class)->costOf($repair->fresh());

        $this->assertSame(38_000, $cost['cost']);
        // ⚠️ `StockMovement::VALUE`: an outgoing quantity is negative and
        // `unit_cost` is unsigned, so MariaDB underflows the bare product.
        $this->assertSame(38_000, (int) -StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)->sum(DB::raw(StockMovement::VALUE)));

        // Net effect on the shelf: bought one, fitted one, holding none.
        $this->assertSame(0, $part->fresh()->quantity);
    }

    /**
     * ⚠️ Buying the same part again adds a batch; it does not add a product.
     *
     * Otherwise "iPhone 12 screen" becomes four products with one batch each,
     * and FIFO across them means nothing at all.
     */
    public function test_buying_a_part_the_shop_already_has_adds_a_batch_not_a_product(): void
    {
        $first = app(RepairService::class)->buyPart(
            name: 'Xiaomi 13 screen', supplier: $this->supplier, quantity: 1,
            unitCost: 38_000, salePrice: 45_000, warrantyDays: 5, user: $this->user(),
        )['product'];

        // Same part, different day, dearer — and typed in a different case.
        $second = app(RepairService::class)->buyPart(
            name: 'xiaomi 13 SCREEN', supplier: $this->supplier, quantity: 2,
            unitCost: 41_000, salePrice: 48_000, warrantyDays: 5, user: $this->user(),
        )['product'];

        $this->assertSame($first->id, $second->id, 'a second product was created for the same part');
        $this->assertSame(1, Product::where('kind', Product::KIND_STOCK)->count());
        $this->assertSame(3, $second->fresh()->quantity);

        $costs = StockBatch::where('product_id', $first->id)->orderBy('id')->pluck('unit_cost');
        $this->assertSame([38_000, 41_000], $costs->map(fn ($c) => (int) $c)->all());

        // The price the shop charges is today's; the older batch keeps its own
        // cost, which is the whole of FIFO.
        $this->assertSame(48_000, (int) $first->fresh()->sale_price);
    }

    /** The screen is only offered to somebody holding the key that spends money. */
    public function test_working_on_repairs_is_not_permission_to_spend_the_shops_money(): void
    {
        $this->actingAs($this->bench(mayBuy: false))
            ->postJson(route('repairs.buy-part'), [
                'name' => 'Xiaomi 13 screen', 'supplier_id' => $this->supplier->id,
                'quantity' => 1, 'unit_cost' => 38_000, 'sale_price' => 45_000, 'warranty_days' => 5,
            ])
            ->assertForbidden();

        $this->assertSame(0, Purchase::count());

        $this->actingAs($this->bench(email: 'rebin2@example.com'))
            ->postJson(route('repairs.buy-part'), [
                'name' => 'Xiaomi 13 screen', 'supplier_id' => $this->supplier->id,
                'quantity' => 1, 'unit_cost' => 38_000, 'sale_price' => 45_000, 'warranty_days' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('product.name', 'Xiaomi 13 screen')
            ->assertJsonPath('product.warranty_days', 5);

        $this->assertSame(1, Purchase::count());
    }

    /**
     * ⚠️ Nobody on a masked cost may buy a part — the one key that leaks the
     * mask rather than merely showing it.
     *
     * They type 20,000 and the job screen shows them 24,000 back through
     * `cost_seen()`. That is the markup, and with the markup every masked cost
     * in the system divides back to the real one.
     */
    public function test_a_masked_reader_cannot_be_given_the_key_that_would_reveal_the_markup(): void
    {
        $admin = $this->user();

        $keys = Permission::whereIn('key', ['auth.login', 'repairs.view', 'repairs.buy_part'])->pluck('id');

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Hama', 'email' => 'hama@example.com',
                'password' => 'a-long-enough-passw0rd', 'password_confirmation' => 'a-long-enough-passw0rd',
                'role' => User::ROLE_USER, 'is_active' => 1,
                'cost_visibility' => User::COST_MARKUP, 'cost_markup_percent' => 20,
                'language' => 'en', 'theme' => 'light', 'items_per_page' => 25,
                'permissions' => $keys->all(),
            ])
            ->assertSessionHas('error');

        $this->assertNull(User::where('email', 'hama@example.com')->first());
    }

    /**
     * ⚠️ The take-in form itself must render, for both readers.
     *
     * Nothing rendered it before, and a wrong scope name — `notWalkIn()` for
     * `companies()` — went in and was only found by opening the page in a
     * browser. Every screen in this module is now opened by a test, because
     * the one that is not is the one that breaks.
     */
    public function test_the_take_in_form_opens_for_a_bench_hand_and_for_the_owner(): void
    {
        // A walk-in is the public, who sell the shop second-hand phones. They
        // must not be offered as somewhere to buy a screen from.
        Supplier::create(['name' => 'Aram who sold his console', 'is_active' => true, 'is_walk_in' => true]);

        foreach ([$this->user(), $this->bench()] as $reader) {
            $page = $this->actingAs($reader)->get(route('repairs.create'));

            $page->assertOk();
            $page->assertSee(__('Buy a part for this job'));
            $page->assertSee('Bazaar Mobile');
            $page->assertDontSee('Aram who sold his console');
        }

        // And the edit form, which takes the same list by the same route.
        $repair = app(RepairService::class)->create(
            customer: $this->customer, device: 'Xiaomi 13', fault: 'Screen smashed',
            user: $this->user(),
        );

        $this->actingAs($this->user())->get(route('repairs.edit', $repair))->assertOk();
    }

    /** Somebody without the key is not shown a button that would refuse them. */
    public function test_the_panel_is_not_shown_to_somebody_who_may_not_buy(): void
    {
        $this->actingAs($this->bench(mayBuy: false))
            ->get(route('repairs.create'))
            ->assertOk()
            ->assertDontSee(__('Buy a part for this job'));
    }

    /**
     * ⚠️ A bench hand is not shown a button that will refuse them.
     *
     * Collecting is a sale and the route has always demanded `sales.create`.
     * The button did not: it was shown to anybody with `repairs.edit`, who
     * pressed it with a customer standing there and got a 403. Section 4
     * refuses a cost contradiction "on the form, rather than left as a trap
     * that looks like it is working" — the same rule applies here.
     */
    public function test_the_bench_is_told_to_send_the_customer_to_the_counter_not_shown_a_403(): void
    {
        $bench = $this->bench();

        $part = app(RepairService::class)->buyPart(
            name: 'Xiaomi 13 screen', supplier: $this->supplier, quantity: 1,
            unitCost: 38_000, salePrice: 45_000, warrantyDays: 5, user: $bench,
        )['product'];

        $repair = app(RepairService::class)->create(
            customer: $this->customer, device: 'Xiaomi 13', fault: 'Screen smashed',
            user: $bench,
            lines: [['product_id' => $part->id, 'quantity' => 1, 'unit_price' => 45_000]],
        );

        app(RepairService::class)->accept($repair, $bench);

        $page = $this->actingAs($bench)->get(route('repairs.show', $repair));

        $page->assertOk();
        $page->assertDontSee(__('Customer collects — make the invoice'), false);
        $page->assertSee(__('Ready for the customer. Collecting it is a sale, so somebody at the counter takes the money and makes the invoice.'), false);

        // And the route still refuses it, which is where the books are kept safe.
        $this->actingAs($bench)
            ->post(route('repairs.collect', $repair), ['amount_paid' => 45_000, 'payment_method' => 'cash'])
            ->assertForbidden();
    }

    /** Nonsense is refused before any money moves. */
    public function test_a_part_with_no_name_or_no_quantity_is_refused(): void
    {
        foreach ([['', 1], ['   ', 1], ['Screen', 0]] as [$name, $quantity]) {
            try {
                app(RepairService::class)->buyPart(
                    name: $name, supplier: $this->supplier, quantity: $quantity,
                    unitCost: 1000, salePrice: 2000, warrantyDays: null, user: $this->user(),
                );
                $this->fail("bought a part called '{$name}' × {$quantity}");
            } catch (RuntimeException) {
                // what should happen
            }
        }

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, Product::where('kind', Product::KIND_STOCK)->count());
    }
}
