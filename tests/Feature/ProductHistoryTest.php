<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Support\RecordHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product's own history, on its own page.
 *
 * activity_logs has recorded all of it since the system was built; the only way
 * to read it was the whole shop's log in one list. This is the same rows, for
 * one product, where somebody stands when they ask why the price is what it is.
 *
 * The reconstruction is the part worth testing. The log stores what a field was
 * before a change and never what it became, so both sides are worked out by
 * reading the entries newest first and rolling the record back through them.
 */
class ProductHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin);

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
            'is_active' => true,
        ]);
    }

    public function test_it_reads_both_sides_of_a_change(): void
    {
        $this->product->update(['sale_price' => 16_500]);

        $history = RecordHistory::for($this->product->refresh());

        $this->assertSame('update', $history[0]['action']);
        $this->assertSame('Administrator', $history[0]['by']);

        $change = collect($history[0]['changes'])->firstWhere('label', 'Sale price');

        $this->assertSame('15,000', $change['from']);
        $this->assertSame('16,500', $change['to']);
    }

    /**
     * The case the rollback exists for. Two edits to one field: the older one
     * has to end where the newer one begins, not at where the field is now.
     */
    public function test_two_edits_to_one_field_chain_together(): void
    {
        $this->product->update(['sale_price' => 16_500]);
        $this->product->refresh()->update(['sale_price' => 18_000]);

        $history = RecordHistory::for($this->product->refresh());

        $newer = collect($history[0]['changes'])->firstWhere('label', 'Sale price');
        $older = collect($history[1]['changes'])->firstWhere('label', 'Sale price');

        $this->assertSame(['16,500', '18,000'], [$newer['from'], $newer['to']]);
        $this->assertSame(['15,000', '16,500'], [$older['from'], $older['to']]);
    }

    /**
     * `updated_at` changes on every save by definition, so it was on every
     * entry of every history — the same date twice, next to the timestamp that
     * already said it.
     */
    public function test_the_timestamp_columns_are_not_listed_as_changes(): void
    {
        $this->product->update(['sale_price' => 16_500]);

        $labels = collect(RecordHistory::for($this->product->refresh())[0]['changes'])
            ->pluck('label');

        $this->assertSame(['Sale price'], $labels->all());
    }

    public function test_the_oldest_entry_is_the_day_it_was_created(): void
    {
        $this->product->update(['name' => 'USB 32GB (Kingston)']);

        $history = RecordHistory::for($this->product->refresh());

        $this->assertSame('create', end($history)['action']);
    }

    /** Stored columns, said the way the shopkeeper says them. */
    public function test_it_says_prices_and_yes_or_no_rather_than_raw_columns(): void
    {
        $other = Category::create(['name' => 'Cables']);

        $this->product->update([
            'is_active' => false,
            'category_id' => $other->id,
            'purchase_price' => 11_500,
        ]);

        $changes = collect(RecordHistory::for($this->product->refresh())[0]['changes'])
            ->keyBy('label');

        $this->assertSame(['Yes', 'No'], [$changes['Active']['from'], $changes['Active']['to']]);
        $this->assertSame(['Flash drives', 'Cables'], [$changes['Category']['from'], $changes['Category']['to']]);
        $this->assertSame(['10,000', '11,500'], [$changes['Purchase price']['from'], $changes['Purchase price']['to']]);
    }

    public function test_the_section_is_on_the_page_for_an_admin(): void
    {
        $this->product->update(['sale_price' => 16_500]);

        $this->actingAs($this->admin)->get(route('products.show', $this->product))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('Sale price')
            ->assertSee('16,500');
    }

    /** It is the shop's audit trail, so it takes the permission for the audit trail. */
    public function test_it_is_withheld_without_the_activity_log_permission(): void
    {
        $this->product->update(['sale_price' => 16_500]);

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'products.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('products.show', $this->product))
            ->assertOk()
            ->assertDontSee('History');

        $staff->permissions()->syncWithoutDetaching(
            Permission::where('key', 'activity_logs.view')->pluck('id')->all()
        );

        $this->actingAs($staff->refresh())->get(route('products.show', $this->product))
            ->assertOk()
            ->assertSee('History');
    }
}
