<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What each member of staff is allowed to see a thing cost.
 *
 * Permissions answer "may they open this screen", and that was not the question
 * the shop was asking: the person at the counter needs the products list all
 * day, and the products list has a purchase price on every row. So the figure
 * itself varies — the real one, the real one plus a percentage, or *****.
 */
class CostVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
            'is_active' => true,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
    }

    // ---- the arithmetic -----------------------------------------------------

    public function test_the_real_cost_is_the_default(): void
    {
        $staff = $this->staff();

        $this->assertTrue($staff->seesRealCost());
        $this->assertSame(10_000, $staff->costAsSeen(10_000));
    }

    public function test_a_markup_moves_the_figure_in_the_shops_favour(): void
    {
        $staff = $this->staff(User::COST_MARKUP, 10);

        $this->assertSame(11_000, $staff->costAsSeen(10_000));
        $this->assertSame(1_100, $staff->costAsSeen(1_000));

        // Whole dinars: Section 2 has no decimals anywhere, so 1,005 at ten
        // per cent is 1,105.5 rounded to 1,106 rather than left as a fraction.
        $this->assertSame(1_106, $staff->costAsSeen(1_005));
    }

    public function test_hidden_means_there_is_no_figure_to_work_from(): void
    {
        $this->assertNull($this->staff(User::COST_HIDDEN)->costAsSeen(10_000));
    }

    /** Section 2: admin has full access, always, and cannot be restricted. */
    public function test_an_admin_sees_the_real_cost_whatever_is_stored(): void
    {
        $this->admin->forceFill(['cost_visibility' => User::COST_HIDDEN, 'cost_markup_percent' => 50])->save();

        $this->assertTrue($this->admin->seesRealCost());
        $this->assertSame(10_000, $this->admin->costAsSeen(10_000));
    }

    // ---- what reaches the screen -------------------------------------------

    public function test_the_products_list_masks_the_purchase_price(): void
    {
        $this->actingAs($this->staff(User::COST_HIDDEN, keys: ['products.view']))
            ->get(route('products.index'))
            ->assertOk()
            ->assertDontSee('10,000')
            ->assertSee(hidden_money());
    }

    public function test_the_products_list_marks_the_purchase_price_up(): void
    {
        $this->actingAs($this->staff(User::COST_MARKUP, 10, keys: ['products.view']))
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('11,000')
            ->assertDontSee('10,000');
    }

    public function test_the_product_page_masks_every_batch(): void
    {
        $this->actingAs($this->staff(User::COST_HIDDEN, keys: ['products.view']))
            ->get(route('products.show', $this->product))
            ->assertOk()
            ->assertDontSee('10,000')
            ->assertSee(hidden_money());
    }

    /**
     * The cart draws its rows from JSON. A figure withheld on one screen and
     * handed over in a response on another is not withheld.
     */
    public function test_the_carts_lookup_does_not_hand_over_the_real_cost(): void
    {
        $hidden = $this->actingAs($this->staff(User::COST_HIDDEN, keys: ['products.view']))
            ->getJson(route('products.search', ['q' => 'USB']))
            ->assertOk()
            ->json('products.0');

        $this->assertNull($hidden['purchase_price']);
        $this->assertNull($hidden['next_batch_cost']);

        $markedUp = $this->actingAs($this->staff(User::COST_MARKUP, 10, 'two@example.com', ['products.view']))
            ->getJson(route('products.search', ['q' => 'USB']))
            ->assertOk()
            ->json('products.0');

        $this->assertSame(11_000, $markedUp['purchase_price']);
        $this->assertSame(11_000, $markedUp['next_batch_cost']);

        // And the sale price, which is nobody's secret, is untouched.
        $this->assertSame(15_000, $markedUp['sale_price']);
    }

    // ---- the two combinations that would do damage --------------------------

    /**
     * Somebody typing a cost has to be typing the real one, or a marked-up
     * figure is saved back as fact and the shop's books quietly become wrong.
     */
    public function test_a_masked_reader_cannot_be_given_a_screen_that_types_costs(): void
    {
        $this->actingAs($this->admin)
            ->from(route('users.create'))
            ->post(route('users.store'), [
                ...$this->form(),
                'cost_visibility' => User::COST_HIDDEN,
                'permissions' => Permission::whereIn('key', ['products.view', 'purchases.create'])->pluck('id')->all(),
            ])
            ->assertRedirect(route('users.create'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'counter@example.com']);
    }

    /** Reports and the purchase documents are the accounts, and are not masked. */
    public function test_a_masked_reader_cannot_be_given_the_screens_that_spell_cost_out(): void
    {
        $this->actingAs($this->admin)
            ->from(route('users.create'))
            ->post(route('users.store'), [
                ...$this->form(),
                'cost_visibility' => User::COST_MARKUP,
                'cost_markup_percent' => 10,
                'permissions' => Permission::whereIn('key', ['products.view', 'reports.view'])->pluck('id')->all(),
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'counter@example.com']);
    }

    public function test_the_combination_is_allowed_once_the_cost_is_real(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                ...$this->form(),
                'cost_visibility' => User::COST_REAL,
                'permissions' => Permission::whereIn('key', ['products.view', 'purchases.create'])->pluck('id')->all(),
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'counter@example.com', 'cost_visibility' => 'real']);
    }

    public function test_the_percentage_is_cleared_when_it_stops_applying(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                ...$this->form(),
                'cost_visibility' => User::COST_HIDDEN,
                'cost_markup_percent' => 25,
                'permissions' => Permission::whereIn('key', ['products.view'])->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'counter@example.com',
            'cost_visibility' => 'hidden',
            'cost_markup_percent' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function form(): array
    {
        return [
            'name' => 'Counter Staff',
            'email' => 'counter@example.com',
            'password' => 'a-strong-password-2026',
            'password_confirmation' => 'a-strong-password-2026',
            'role' => User::ROLE_USER,
            'is_active' => 1,
            'language' => 'en',
            'theme' => 'auto',
            'items_per_page' => 25,
        ];
    }

    /** @param  list<string>  $keys */
    private function staff(
        string $visibility = User::COST_REAL,
        int $percent = 0,
        string $email = 'assistant@example.com',
        array $keys = ['products.view'],
    ): User {
        $user = User::create([
            'name' => 'Shop Assistant', 'email' => $email,
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
            'cost_visibility' => $visibility, 'cost_markup_percent' => $percent,
        ]);

        $user->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id')->all());

        return $user;
    }
}
