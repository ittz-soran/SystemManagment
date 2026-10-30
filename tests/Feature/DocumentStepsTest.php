<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\DocumentSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * Previous and next on a document's page — PROJECT_DOC Section 9b, Document
 * detail page. Soran, 2026-10-30: *"for ex i am in INV-00110 and i want go to
 * after this or before this, if tab next go INV-00111 if have and back to
 * INV-00109. this feature for INV,PUR,PRT,SRT,ADJ,PAY,EXP and other page can
 * add this."*
 */
class DocumentStepsTest extends TestCase
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
            'name' => 'Cable', 'sku' => 'C1', 'category_id' => Category::firstOrCreate(['name' => 'Parts'])->id,
            'unit' => 'pcs', 'purchase_price' => 1_000, 'sale_price' => 2_500, 'quantity' => 0,
        ]);
    }

    private function purchase(): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: Supplier::firstOrCreate(['name' => 'Click Gaming']),
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1_000]],
            user: $this->admin, purchaseDate: now(),
        );
    }

    private function sale(): Sale
    {
        return app(SaleService::class)->create(
            customer: Customer::cashCustomer(),
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 2_500]],
            user: $this->admin, saleDate: now(), amountPaid: 2_500,
        );
    }

    /** @return list<Sale> */
    private function sales(int $count): array
    {
        $this->purchase();

        return array_map(fn () => $this->sale(), range(1, $count));
    }

    /** The pair as it is drawn on the page. */
    private function steps(string $url): string
    {
        $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
        $start = strpos($html, 'aria-label="'.__('Previous and next').'"');
        $this->assertNotFalse($start, 'The page has no previous and next.');

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
    }

    public function test_an_invoice_steps_to_the_one_before_and_the_one_after(): void
    {
        [$first, $middle, $last] = $this->sales(3);

        $steps = $this->steps(route('sales.show', $middle));

        $this->assertStringContainsString('href="'.route('sales.show', $first).'" rel="prev"', $steps);
        $this->assertStringContainsString('&lsaquo; '.$first->document_no, $steps);
        $this->assertStringContainsString('href="'.route('sales.show', $last).'" rel="next"', $steps);
        $this->assertStringContainsString($last->document_no.' &rsaquo;', $steps);
    }

    /** At either end the arrow stays, greyed, so the pair does not move. */
    public function test_at_either_end_the_arrow_is_there_but_goes_nowhere(): void
    {
        [$first, , $last] = $this->sales(3);

        $atFirst = $this->steps(route('sales.show', $first));
        $this->assertStringNotContainsString('rel="prev"', $atFirst);
        $this->assertStringContainsString('aria-disabled="true" title="'.__('This is the first').'"', $atFirst);
        $this->assertStringContainsString('rel="next"', $atFirst);

        $atLast = $this->steps(route('sales.show', $last));
        $this->assertStringNotContainsString('rel="next"', $atLast);
        $this->assertStringContainsString('aria-disabled="true" title="'.__('This is the last').'"', $atLast);
    }

    /** A deleted invoice keeps its number but has no page, so it is stepped over. */
    public function test_a_deleted_document_is_stepped_over(): void
    {
        [$first, $middle, $last] = $this->sales(3);
        $middle->delete();

        $steps = DocumentSteps::around($first);
        $this->assertSame($last->document_no, $steps['next']['number']);

        $this->assertSame($first->document_no, DocumentSteps::around($last)['previous']['number']);
    }

    /**
     * ⚠️ By number, ordered by length and then by text: past INV-99999 the
     * text alone would put INV-100000 first.
     */
    public function test_the_order_is_the_numbers_even_past_five_digits(): void
    {
        [$a, $b, $c] = $this->sales(3);

        // Numbered against the order the rows were made in.
        $a->forceFill(['document_no' => 'INV-100000'])->save();
        $b->forceFill(['document_no' => 'INV-99999'])->save();
        $c->forceFill(['document_no' => 'INV-99998'])->save();

        $this->assertSame('INV-99999', DocumentSteps::around($c)['next']['number']);
        $this->assertSame('INV-100000', DocumentSteps::around($b)['next']['number']);
        $this->assertSame('INV-99999', DocumentSteps::around($a)['previous']['number']);
        $this->assertNull(DocumentSteps::around($a)['next']);
        $this->assertNull(DocumentSteps::around($c)['previous']);
    }

    /** Each kind steps among its own: a purchase's next is a purchase. */
    public function test_a_purchase_steps_among_purchases(): void
    {
        $first = $this->purchase();
        $this->sale();
        $second = $this->purchase();

        $steps = $this->steps(route('purchases.show', $first));
        $this->assertStringContainsString('href="'.route('purchases.show', $second).'" rel="next"', $steps);
        $this->assertStringContainsString($second->document_no, $steps);
    }

    /** The eleven numbered documents, each page carrying the pair. */
    public function test_every_numbered_document_page_carries_the_pair(): void
    {
        $pages = (new ReflectionClass(DocumentSteps::class))->getConstant('PAGES');

        // Twelve since the stock check's rounds, 2026-10-30.
        $this->assertCount(12, $pages);

        foreach ($pages as $model => $route) {
            $this->assertTrue(Route::has($route), "{$route} for {$model}");
        }

        foreach (['sales', 'purchases', 'sale-returns', 'purchase-returns', 'payments', 'expenses',
            'stock-adjustments', 'stock-transfers', 'repairs', 'swaps', 'assemblies', 'stock-checks'] as $folder) {
            $this->assertStringContainsString(
                "@section('steps')<x-document-steps",
                file_get_contents(resource_path("views/{$folder}/show.blade.php")),
                "{$folder}/show has no previous and next",
            );
        }
    }

    /** A page that is not a numbered document has no pair. */
    public function test_a_customer_page_has_no_pair(): void
    {
        $this->actingAs($this->admin)->get(route('customers.show', Customer::cashCustomer()))
            ->assertOk()
            ->assertDontSee('aria-label="'.__('Previous and next').'"', false);
    }

    /** The menu keeps its place across a reload; the script that does it is there. */
    public function test_the_menu_keeps_its_place(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee("const KEY = 'nav:sidebar-scroll';", false);
    }
}
