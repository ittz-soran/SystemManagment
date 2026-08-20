<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Section 4: the SKU and barcode rules. */
class ProductCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_it_computes_valid_ean13_check_digits(): void
    {
        $service = app(ProductCodeService::class);

        // Known-good real barcodes. 4006381333931 weights to 89, so the check
        // digit is 1; 5901234123457 weights to 83, so it is 7.
        $this->assertSame(1, $service->ean13CheckDigit('400638133393'), '4006381333931');
        $this->assertSame(7, $service->ean13CheckDigit('590123412345'), '5901234123457');

        $this->assertTrue($service->isValidEan13('4006381333931'));
        $this->assertFalse($service->isValidEan13('4006381333930'), 'A wrong check digit is rejected');
        $this->assertFalse($service->isValidEan13('12345'), 'Wrong length is rejected');
    }

    public function test_generated_barcodes_are_valid_and_in_the_internal_prefix_range(): void
    {
        $service = app(ProductCodeService::class);

        $barcodes = DB::transaction(fn () => collect(range(1, 5))->map(fn () => $service->generateBarcode()));

        foreach ($barcodes as $barcode) {
            $this->assertTrue($service->isValidEan13($barcode), "{$barcode} must be a valid EAN-13");

            $prefix = (int) substr($barcode, 0, 3);
            $this->assertGreaterThanOrEqual(200, $prefix, 'Internal-use prefix range 200-299');
            $this->assertLessThanOrEqual(299, $prefix, 'Internal-use prefix range 200-299');
        }

        $this->assertSame(5, $barcodes->unique()->count(), 'Generated barcodes are unique');
    }

    public function test_skus_use_the_configured_prefix_and_a_counter(): void
    {
        $service = app(ProductCodeService::class);

        $first = DB::transaction(fn () => $service->generateSku());
        $second = DB::transaction(fn () => $service->generateSku());

        $this->assertSame('SS1', $first);
        $this->assertSame('SS2', $second);
    }

    /**
     * Section 4: auto-generated numbers come from a counter, never from MAX(id) —
     * deleting a product must not let a code be reused.
     */
    public function test_a_deleted_product_does_not_release_its_code(): void
    {
        $service = app(ProductCodeService::class);
        $category = Category::create(['name' => 'Test']);

        $codes = DB::transaction(fn () => $service->resolve([]));

        $product = Product::create([
            'name' => 'Doomed', 'sku' => $codes['sku'], 'barcode' => $codes['barcode'],
            'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 0, 'quantity' => 0,
        ]);

        $product->delete();

        $next = DB::transaction(fn () => $service->resolve([]));

        $this->assertNotSame($codes['sku'], $next['sku'], 'The SKU is not handed out again');
        $this->assertNotSame($codes['barcode'], $next['barcode'], 'The barcode is not handed out again');
    }

    /** A typed manufacturer code is kept as-is; only blanks are generated. */
    public function test_typed_codes_are_preserved(): void
    {
        $resolved = DB::transaction(fn () => app(ProductCodeService::class)->resolve([
            'sku' => 'C175',
            'barcode' => '4006381333931',
        ]));

        $this->assertSame('C175', $resolved['sku']);
        $this->assertSame('4006381333931', $resolved['barcode']);
    }
}
