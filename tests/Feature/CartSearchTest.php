<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the till's search box finds — Section 9b.
 *
 * **Found by Soran using it, 2026-09-12: "while search by SKU not suggesting
 * anything".** The partial search matched `name` only. A SKU or a barcode was
 * matched exactly, as a whole string, so typing part of one found nothing at
 * all and the box looked broken. At a till, where the SKU is printed on the box
 * in your hand and the name is whatever somebody typed months ago, that is the
 * wrong way round.
 */
class CartSearchTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, string $sku, ?string $barcode = null): Product
    {
        return Product::create([
            'name' => $name, 'sku' => $sku, 'barcode' => $barcode,
            'category_id' => Category::firstOrCreate(['name' => 'C'])->id,
            'unit' => 'pcs', 'purchase_price' => 100, 'sale_price' => 200, 'quantity' => 5,
        ]);
    }

    private function search(string $term): array
    {
        $this->seed();

        return $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
            ->getJson(route('products.search', ['q' => $term]))
            ->assertOk()
            ->json();
    }

    public function test_part_of_a_sku_finds_the_product(): void
    {
        $this->product('4in1 Wireless Adapter Go-Des', 'GD-BT208', '6972496470268');

        $found = $this->search('BT208');

        $this->assertCount(1, $found['products']);
        $this->assertSame('GD-BT208', $found['products'][0]['sku']);
    }

    public function test_part_of_a_barcode_finds_the_product(): void
    {
        $this->product('4in1 Wireless Adapter Go-Des', 'GD-BT208', '6972496470268');

        $found = $this->search('64702');

        $this->assertCount(1, $found['products']);
    }

    /** A whole code still adds straight to the cart, which is the scanner's path. */
    public function test_a_whole_code_is_still_exact(): void
    {
        $this->product('4in1 Wireless Adapter Go-Des', 'GD-BT208', '6972496470268');

        $this->assertTrue($this->search('6972496470268')['exact']);
        $this->assertTrue($this->search('GD-BT208')['exact']);
        $this->assertFalse($this->search('BT208')['exact'], 'A partial code must suggest, not add.');
    }

    /** The code someone is reading off a box comes before a name that happens to contain it. */
    public function test_a_code_that_starts_with_the_term_is_offered_first(): void
    {
        $this->product('Cable OT48 mention in the name', 'ZZ-999');
        $this->product('Adapter Earldom LTG to USB', 'OT48X');

        $found = $this->search('OT48');

        $this->assertSame('OT48X', $found['products'][0]['sku']);
    }

    /**
     * ⚠️ The trap in the fix itself.
     *
     * Adding `orWhere` for the SKU without grouping it escapes `active()` and
     * `ofKind()`, and the till is then offered a discontinued product to sell.
     */
    public function test_it_never_offers_a_product_the_caller_may_not_use(): void
    {
        $gone = $this->product('Retired thing', 'GD-OLD');
        $gone->update(['is_active' => false]);

        $this->assertSame([], $this->search('GD-OLD')['products']);
    }
}
