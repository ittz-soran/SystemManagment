<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A deleted product still holds its SKU and its barcode.
 *
 * The doc's schema is unique (sku) and unique (barcode) across the whole table,
 * and a soft-deleted row is still in the table — so those codes are not free.
 * The form used to say they were: it skipped the deleted rows when checking,
 * passed, and then broke on the way into the database. The shopkeeper saw a
 * server error where there should have been a sentence, and no way at all to
 * get back the product holding the code.
 */
class DeletedProductCodesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->category = Category::create(['name' => 'Flash drives']);
    }

    private function deletedProduct(): Product
    {
        $product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'barcode' => '6221031492754',
            'category_id' => $this->category->id, 'unit' => 'pcs',
            'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        $product->delete();

        return $product;
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return [
            'name' => 'USB 32GB again',
            'category_id' => $this->category->id,
            'unit' => 'pcs',
            'purchase_price' => 10_000,
            'sale_price' => 15_000,
            ...$overrides,
        ];
    }

    public function test_reusing_a_deleted_products_barcode_is_refused_by_name(): void
    {
        $gone = $this->deletedProduct();

        $response = $this->actingAs($this->admin)
            ->from(route('products.create'))
            ->post(route('products.store'), $this->form(['barcode' => $gone->barcode]));

        $response->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('barcode');

        $this->assertStringContainsString(
            $gone->name,
            session('errors')->first('barcode'),
            'The message has to say which product is holding it',
        );
    }

    public function test_reusing_a_deleted_products_sku_is_refused_by_name(): void
    {
        $gone = $this->deletedProduct();

        $this->actingAs($this->admin)
            ->from(route('products.create'))
            ->post(route('products.store'), $this->form(['sku' => $gone->sku]))
            ->assertSessionHasErrors('sku');

        $this->assertStringContainsString($gone->name, session('errors')->first('sku'));
    }

    /** The whole point of the guard: a sentence, not a server error. */
    public function test_it_never_reaches_the_database_and_breaks(): void
    {
        $gone = $this->deletedProduct();

        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->form([
                'sku' => $gone->sku,
                'barcode' => $gone->barcode,
            ]))
            ->assertRedirect();

        $this->assertSame(1, Product::withTrashed()->where('sku', $gone->sku)->count());
    }

    public function test_the_products_list_can_show_the_deleted_ones(): void
    {
        $gone = $this->deletedProduct();

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertOk()
            ->assertDontSee($gone->sku);

        $this->actingAs($this->admin)->get(route('products.index', ['deleted' => 1]))
            ->assertOk()
            ->assertSee($gone->sku)
            ->assertSee(route('products.restore', $gone));
    }

    public function test_bringing_one_back_frees_its_codes_again(): void
    {
        $gone = $this->deletedProduct();

        $this->actingAs($this->admin)
            ->post(route('products.restore', $gone))
            ->assertRedirect(route('products.show', $gone));

        $this->assertFalse($gone->refresh()->trashed());

        // And the codes are the restored product's again, not free for a new one.
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->form(['sku' => $gone->sku]))
            ->assertSessionHasErrors('sku');
    }

    /** A live clash names the product too — hunting a list for it was the old way. */
    public function test_a_clash_with_a_product_still_on_the_list_names_it(): void
    {
        Product::create([
            'name' => 'Cable, 2m', 'sku' => 'CBL1',
            'category_id' => $this->category->id, 'unit' => 'pcs',
            'purchase_price' => 1_000, 'sale_price' => 2_000, 'quantity' => 0,
        ]);

        $this->actingAs($this->admin)
            ->from(route('products.create'))
            ->post(route('products.store'), $this->form(['sku' => 'CBL1']))
            ->assertSessionHasErrors('sku');

        $message = session('errors')->first('sku');

        $this->assertStringContainsString('Cable, 2m', $message);
        $this->assertStringNotContainsString('deleted', $message);
    }

    public function test_a_product_that_was_never_deleted_has_nothing_to_bring_back(): void
    {
        $live = Product::create([
            'name' => 'Cable', 'sku' => 'CBL1',
            'category_id' => $this->category->id, 'unit' => 'pcs',
            'purchase_price' => 1_000, 'sale_price' => 2_000, 'quantity' => 0,
        ]);

        $this->actingAs($this->admin)->post(route('products.restore', $live))->assertNotFound();
    }

    /** Editing a product must not trip over the code it already holds. */
    public function test_a_product_can_keep_its_own_codes_when_edited(): void
    {
        $product = Product::create([
            'name' => 'Cable', 'sku' => 'CBL1', 'barcode' => '6221031492754',
            'category_id' => $this->category->id, 'unit' => 'pcs',
            'purchase_price' => 1_000, 'sale_price' => 2_000, 'quantity' => 0,
        ]);

        $this->actingAs($this->admin)
            ->put(route('products.update', $product), $this->form([
                'name' => 'Cable, 2m',
                'sku' => 'CBL1',
                'barcode' => '6221031492754',
                'purchase_price' => 1_000,
                'sale_price' => 2_000,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Cable, 2m', $product->refresh()->name);
    }
}
