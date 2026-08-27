<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a supplier or a customer.
 *
 * Both had a working update endpoint and no way to reach it: no pencil on the
 * row, no button on their own page. A phone number typed wrong stayed wrong.
 */
class PeopleEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_a_supplier_can_be_corrected_from_the_list(): void
    {
        $supplier = Supplier::create(['name' => 'Bazar Mobil', 'phone' => '0770']);

        $this->actingAs($this->admin)->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee('data-action="'.route('suppliers.update', $supplier).'"', false);

        $this->actingAs($this->admin)
            ->put(route('suppliers.update', $supplier), [
                'name' => 'Bazaar Mobile',
                'phone' => '07701112233',
                'address' => 'Erbil',
            ])
            ->assertSessionHasNoErrors();

        $supplier->refresh();

        $this->assertSame('Bazaar Mobile', $supplier->name);
        $this->assertSame('07701112233', $supplier->phone);
        $this->assertSame('Erbil', $supplier->address);
    }

    public function test_a_supplier_can_be_corrected_from_its_own_page(): void
    {
        $supplier = Supplier::create(['name' => 'Bazar Mobil']);

        $this->actingAs($this->admin)->get(route('suppliers.show', $supplier))
            ->assertOk()
            ->assertSee('data-action="'.route('suppliers.update', $supplier).'"', false);
    }

    public function test_a_customer_can_be_corrected(): void
    {
        $customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        $this->actingAs($this->admin)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('data-action="'.route('customers.update', $customer).'"', false);

        $this->actingAs($this->admin)
            ->put(route('customers.update', $customer), [
                'name' => 'Karwan Ahmed',
                'phone' => '07501112233',
                'address' => 'Sulaymaniyah',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Karwan Ahmed', $customer->refresh()->name);
    }

    /** Section 4: the Cash Customer cannot be renamed, so it is not offered a pencil. */
    public function test_the_cash_customer_is_not_offered_an_edit(): void
    {
        $cash = Customer::where('is_system', true)->firstOrFail();

        $this->actingAs($this->admin)->get(route('customers.index'))
            ->assertOk()
            ->assertDontSee('data-action="'.route('customers.update', $cash).'"', false);

        $this->actingAs($this->admin)
            ->put(route('customers.update', $cash), ['name' => 'Renamed'])
            ->assertSessionHas('error');

        $this->assertNotSame('Renamed', $cash->refresh()->name);
    }

    public function test_the_pencil_needs_the_edit_permission(): void
    {
        $supplier = Supplier::create(['name' => 'Bazar Mobil']);

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'suppliers.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('suppliers.index'))
            ->assertOk()
            ->assertDontSee('data-action="'.route('suppliers.update', $supplier).'"', false);

        $this->actingAs($staff)->put(route('suppliers.update', $supplier), ['name' => 'Nope'])
            ->assertForbidden();
    }
}
