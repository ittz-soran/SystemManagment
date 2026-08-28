<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a payment that was written down wrong.
 *
 * Section 8's shape, the one every other document already uses: reverse what it
 * did to the ledger, then post it again with the new figures, inside one
 * transaction. Not a difference — 20,000 corrected to 15,000 is the whole
 * 20,000 put back and a fresh 15,000 taken, so the balance ends where it would
 * have if the figure had been right the first time.
 */
class PaymentEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin);

        $product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000,
            'quantity' => 0, 'is_active' => true,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        $this->customer = Customer::create(['name' => 'Karwan']);

        // Sold for 60,000 with nothing paid, so the customer owes all of it.
        $this->sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );
    }

    private function pay(int $amount): Payment
    {
        $this->actingAs($this->admin)->post(route('payments.store'), [
            'payable_type' => 'sale',
            'payable_id' => $this->sale->id,
            'amount' => $amount,
            'direction' => Payment::DIRECTION_IN,
            'payment_method' => 'cash',
            'paid_at' => today()->toDateString(),
        ])->assertRedirect();

        return Payment::latest('id')->firstOrFail();
    }

    public function test_correcting_the_amount_moves_the_balance_to_where_it_should_have_been(): void
    {
        $this->assertSame(60_000, (int) $this->customer->refresh()->balance);

        $payment = $this->pay(20_000);
        $this->assertSame(40_000, (int) $this->customer->refresh()->balance);

        $this->actingAs($this->admin)
            ->put(route('payments.update', $payment), [
                'amount' => 15_000,
                'direction' => Payment::DIRECTION_IN,
                'payment_method' => 'cash',
                'paid_at' => today()->toDateString(),
            ])
            ->assertRedirect(route('payments.show', $payment))
            ->assertSessionHasNoErrors();

        $this->assertSame(15_000, (int) $payment->refresh()->amount);
        $this->assertSame(45_000, (int) $this->customer->refresh()->balance,
            'The whole 20,000 goes back and a fresh 15,000 comes off');
    }

    public function test_the_document_number_survives_the_edit(): void
    {
        $payment = $this->pay(20_000);
        $number = $payment->document_no;

        $this->actingAs($this->admin)->put(route('payments.update', $payment), [
            'amount' => 25_000,
            'direction' => Payment::DIRECTION_IN,
            'payment_method' => 'bank',
            'paid_at' => today()->toDateString(),
            'notes' => 'Paid by transfer after all',
        ])->assertSessionHasNoErrors();

        $payment->refresh();

        $this->assertSame($number, $payment->document_no);
        $this->assertSame('bank', $payment->payment_method);
        $this->assertSame('Paid by transfer after all', $payment->notes);
    }

    /** Every other correction ends where a delete and a fresh record would. */
    public function test_it_lands_where_deleting_and_recording_again_would(): void
    {
        $payment = $this->pay(20_000);

        $this->actingAs($this->admin)->put(route('payments.update', $payment), [
            'amount' => 35_000,
            'direction' => Payment::DIRECTION_IN,
            'payment_method' => 'cash',
            'paid_at' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $edited = (int) $this->customer->refresh()->balance;

        // The long way round, on a second sale of the same shape.
        $this->actingAs($this->admin)->delete(route('payments.destroy', $payment));
        $this->pay(35_000);

        $this->assertSame($edited, (int) $this->customer->refresh()->balance);
    }

    public function test_a_closed_period_cannot_be_edited_into_or_out_of(): void
    {
        $payment = $this->pay(20_000);

        Setting::updateOrCreate(
            ['key' => 'books_closed_before'],
            ['value' => today()->addDay()->toDateString()],
        );
        Setting::flushCache();

        $this->actingAs($this->admin)
            ->from(route('payments.edit', $payment))
            ->put(route('payments.update', $payment), [
                'amount' => 15_000,
                'direction' => Payment::DIRECTION_IN,
                'payment_method' => 'cash',
                'paid_at' => today()->toDateString(),
            ])
            ->assertSessionHas('error');

        $this->assertSame(20_000, (int) $payment->refresh()->amount);
    }

    public function test_the_screen_needs_the_edit_permission(): void
    {
        $payment = $this->pay(20_000);

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'payments.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('payments.edit', $payment))->assertForbidden();

        $staff->permissions()->syncWithoutDetaching(
            Permission::where('key', 'payments.edit')->pluck('id')->all()
        );

        $this->actingAs($staff->refresh())->get(route('payments.edit', $payment))->assertOk();
    }
}
