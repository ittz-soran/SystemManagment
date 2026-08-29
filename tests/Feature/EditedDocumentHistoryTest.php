<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\RecordHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sale that was edited afterwards still opens.
 *
 * The shopkeeper finished INV-00010, went back into it the next day and added a
 * line, and from then on the invoice would not open at all: "Array to string
 * conversion". The edit services store the whole previous cart in old_values
 * under `lines`, the history read every stored field as if it were one figure,
 * and the one document anybody had edited was the one document nobody could
 * see. Every other invoice was fine, which is exactly what made it frightening.
 */
class EditedDocumentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $usb;

    private Product $cable;

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

        $this->cable = Product::create([
            'name' => 'Type-C cable', 'sku' => 'TYPEC', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 2_000, 'sale_price' => 5_000,
            'quantity' => 0, 'is_active' => true,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [
                ['product_id' => $this->usb->id, 'quantity' => 20, 'unit_price' => 10_000],
                ['product_id' => $this->cable->id, 'quantity' => 20, 'unit_price' => 2_000],
            ],
            user: $this->admin, purchaseDate: now()->subDays(2), amountPaid: 0,
        );
    }

    public function test_a_sale_with_a_line_added_after_the_fact_still_opens(): void
    {
        $customer = Customer::create(['name' => 'Karwan']);

        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $this->usb->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now()->subDay(), amountPaid: 0,
        );

        // The next day, back into the invoice to add the cable they forgot.
        app(SaleService::class)->update(
            sale: $sale,
            customer: $customer,
            lines: [
                ['product_id' => $this->usb->id, 'quantity' => 2, 'unit_price' => 15_000],
                ['product_id' => $this->cable->id, 'quantity' => 1, 'unit_price' => 5_000],
            ],
            user: $this->admin, saleDate: now()->subDay(),
        );

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee(__('History'), false);
    }

    public function test_the_history_says_what_the_cart_was_and_what_it_became(): void
    {
        $customer = Customer::create(['name' => 'Karwan']);

        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $this->usb->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now()->subDay(), amountPaid: 0,
        );

        app(SaleService::class)->update(
            sale: $sale,
            customer: $customer,
            lines: [
                ['product_id' => $this->usb->id, 'quantity' => 2, 'unit_price' => 15_000],
                ['product_id' => $this->cable->id, 'quantity' => 1, 'unit_price' => 5_000],
            ],
            user: $this->admin, saleDate: now()->subDay(),
        );

        $history = RecordHistory::for($sale->refresh());
        $edit = collect($history)->firstWhere('action', 'update');

        $this->assertNotNull($edit, 'the edit is in the history');

        $lines = collect($edit['changes'])->firstWhere('label', __('Items'));

        $this->assertNotNull($lines, 'the cart change is one of the lines');
        $this->assertSame('2 × USB 32GB @ 15,000', $lines['from']);
        $this->assertSame('2 × USB 32GB @ 15,000, 1 × Type-C cable @ 5,000', $lines['to']);
    }

    /** Nothing changed is nothing said: the snapshot stores every field, not just the moved ones. */
    public function test_fields_the_edit_left_alone_are_not_listed(): void
    {
        $customer = Customer::create(['name' => 'Karwan']);

        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $this->usb->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now()->subDay(), amountPaid: 0,
        );

        app(SaleService::class)->update(
            sale: $sale, customer: $customer,
            lines: [['product_id' => $this->usb->id, 'quantity' => 3, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now()->subDay(),
        );

        $edit = collect(RecordHistory::for($sale->refresh()))->firstWhere('action', 'update');
        $labels = collect($edit['changes'])->pluck('label')->all();

        $this->assertContains(__('Items'), $labels);
        $this->assertContains(__('Total'), $labels);
        $this->assertNotContains(__('Document number'), $labels);
        $this->assertNotContains(__('Customer'), $labels);
    }

    public function test_an_edited_purchase_still_opens_too(): void
    {
        $supplier = Supplier::create(['name' => 'Erbil Traders']);

        $purchase = app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->usb->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now()->subDay(), amountPaid: 0,
        );

        app(PurchaseService::class)->update(
            purchase: $purchase,
            supplier: $supplier,
            lines: [
                ['product_id' => $this->usb->id, 'quantity' => 5, 'unit_price' => 10_000],
                ['product_id' => $this->cable->id, 'quantity' => 3, 'unit_price' => 2_000],
            ],
            user: $this->admin, purchaseDate: now()->subDay(),
        );

        $this->get(route('purchases.show', $purchase))
            ->assertOk()
            ->assertSee(__('History'), false);
    }
}
