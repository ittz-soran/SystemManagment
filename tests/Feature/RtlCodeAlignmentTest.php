<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A code reads left-to-right; it does not move to the left-hand side.
 *
 * **Soran pointed at this on the Products list in Kurdish, 2026-09-12:** the
 * SKU line `GD-BT208 · 6972496470268` stranded against the left edge of the
 * row, under a product name sitting correctly on the right.
 *
 * The cause is a rule of HTML rather than a mistake in the markup:
 * `dir="ltr"` on a BLOCK element sets its text ALIGNMENT as well as its
 * direction. Written that way a code is correctly ordered and wrongly placed,
 * and three of the four languages this shop ships in are right-to-left — so it
 * was wrong on three screens out of four, everywhere a code appeared.
 *
 * `.app-code` is an inline-block instead: LTR inside, one box the parent aligns
 * from the outside. This holds that the fix stays.
 */
class RtlCodeAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sku_line_is_not_left_aligned_in_an_rtl_language(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin->update(['language' => 'ckb']);

        Product::create([
            'name' => '4in1 Wireless Adapter Go-Des', 'sku' => 'GD-BT208',
            'barcode' => '6972496470268',
            'category_id' => Category::firstOrCreate(['name' => 'C'])->id,
            'unit' => 'pcs', 'purchase_price' => 8_000, 'sale_price' => 15_000, 'quantity' => 3,
        ]);

        $page = $this->actingAs($admin)->get(route('products.index'))->assertOk();

        // The code is there, in a box that reads LTR…
        $page->assertSee('app-code', false);
        $page->assertSee('GD-BT208 · 6972496470268', false);

        // …and NOT in a block that drags it to the left-hand side.
        $this->assertStringNotContainsString(
            '<div class="small text-secondary" dir="ltr">',
            $page->getContent(),
            'A block dir="ltr" left-aligns the code in Kurdish, Arabic and Persian.',
        );
    }

    /** The utility itself has to be in the compiled stylesheet, or none of it applies. */
    public function test_the_utility_is_in_the_built_stylesheet(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->markTestSkipped('No compiled assets here — nothing to check.');
        }

        $read = json_decode((string) file_get_contents($manifest), true);
        $css = collect($read)->pluck('file')->first(fn ($f) => str_ends_with((string) $f, '.css'));

        $this->assertStringContainsString(
            '.app-code',
            (string) file_get_contents(public_path('build/'.$css)),
            'The SCSS was changed without running `npm run build` — the fix ships without its CSS.',
        );
    }
}
