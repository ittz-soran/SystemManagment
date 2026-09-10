<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The trend chart, on the screens that carry it.
 *
 * What is proved here is not the arithmetic — DailyTotalsTest and ChartTest do
 * that — but the two things a template can get wrong on its own: whether the
 * drawing is really there before any script runs, and whether a reader who may
 * not see cost is shown a profit line anyway.
 */
class TrendChartTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->trade();
    }

    private function trade(): void
    {
        $product = Product::create([
            'name' => 'Cable', 'sku' => 'C1', 'unit' => 'pcs',
            'category_id' => Category::create(['name' => 'Wires'])->id,
            'purchase_price' => 0, 'sale_price' => 10_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [['product_id' => $product->id, 'quantity' => 20, 'unit_price' => 6_000]],
            user: $this->admin, purchaseDate: today()->subDays(5),
        );

        app(SaleService::class)->create(
            customer: Customer::create(['name' => 'C']),
            lines: [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10_000]],
            user: $this->admin, saleDate: today()->subDays(2),
        );
    }

    private function staff(array $permissions, string $costVisibility = User::COST_REAL): User
    {
        $user = User::create([
            'name' => 'Shop Assistant',
            'email' => 'assistant'.count($permissions).$costVisibility.'@example.com',
            'password' => 'a-strong-password-2026',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'language' => 'en',
            'theme' => 'auto',
            'items_per_page' => 25,
            'cost_visibility' => $costVisibility,
        ]);

        $user->permissions()->sync(
            \App\Models\Permission::whereIn('key', $permissions)->pluck('id')
        );

        return $user->fresh();
    }

    public function test_the_drawing_is_there_before_any_script_runs(): void
    {
        // The whole reason there is no charting library: this page gets printed,
        // and a canvas chart prints as an empty box. The SVG has to arrive with
        // the page, not be built afterwards.
        $response = $this->actingAs($this->admin)->get(route('reports.index'))->assertOk();

        $response->assertSee('app-trend-line', escape: false);
        $response->assertSee('<path d="M', escape: false);

        // And the band beneath it, on its own scale.
        $response->assertSee('app-trend-band', escape: false);
        $response->assertSee(__('Stock value'));
    }

    public function test_the_legend_is_plain_text_until_a_script_upgrades_it(): void
    {
        // A button that does nothing is a promise the printed page cannot keep.
        $body = $this->actingAs($this->admin)->get(route('reports.index'))->getContent();

        $this->assertStringContainsString('app-trend-chip', $body);
        $this->assertStringNotContainsString('app-trend-chip" role="button"', $body);
    }

    public function test_the_dashboard_draws_the_same_chart(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('The last four weeks'))
            ->assertSee('app-trend-line', escape: false);
    }

    public function test_a_reader_kept_from_cost_gets_no_profit_line_and_no_shelf(): void
    {
        // Profit is a subtraction away from cost, and stock value *is* a cost.
        // Drawing either for somebody the shop keeps cost from hands it back by
        // arithmetic.
        //
        // The users screen will not hand reports.view to a masked reader in the
        // first place — CostVisibilityTest proves that — so this is the shop
        // whose data drifted into the combination afterwards, which is exactly
        // when a chart must not be the thing that leaks.
        $user = $this->staff(['dashboard.view', 'sales.view', 'purchases.view', 'reports.view']);
        $user->forceFill(['cost_visibility' => User::COST_HIDDEN])->save();

        $trend = $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->viewData('trend');

        $this->assertNotNull($trend);
        $this->assertSame([__('Sales'), __('Purchases')], array_column($trend['series'], 'name'));
        $this->assertNull($trend['level'], 'the shelf is a cost and does not appear');
    }

    public function test_a_marked_up_cost_gives_the_profit_that_markup_implies(): void
    {
        $user = $this->staff(['dashboard.view', 'sales.view', 'reports.view']);
        $user->forceFill(['cost_visibility' => User::COST_MARKUP, 'cost_markup_percent' => 25])->save();

        $trend = $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->viewData('trend');

        $profit = collect($trend['series'])->firstWhere('name', __('Profit'));

        $this->assertNotNull($profit, 'a marked-up cost is still a cost they may work from');

        // 30,000 taken against 18,000 of real cost, shown to them as 22,500.
        $this->assertContains(7_500, $profit['values']);
        $this->assertNotContains(12_000, $profit['values'], 'the real profit is never on the page');
    }

    public function test_the_dashboard_chart_only_draws_what_the_reader_may_open(): void
    {
        // The same rule the tiles follow: a purchases line drawn for somebody
        // kept out of the purchases screen has told them what withholding it
        // was for.
        $user = $this->staff(['dashboard.view', 'sales.view']);

        $trend = $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('trend');

        $this->assertSame([__('Sales')], array_column($trend['series'], 'name'));
        $this->assertNull($trend['level']);
    }

    public function test_a_reader_with_nothing_to_see_gets_no_empty_frame(): void
    {
        $user = $this->staff(['dashboard.view']);

        $this->assertNull(
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('trend')
        );
    }

    public function test_a_product_page_charts_that_product_and_no_other(): void
    {
        $product = Product::where('sku', 'C1')->firstOrFail();

        $trend = $this->actingAs($this->admin)
            ->get(route('products.show', $product))
            ->assertOk()
            ->viewData('trend');

        $this->assertSame([__('Units sold')], array_column($trend['series'], 'name'));
        $this->assertContains(3, $trend['series'][0]['values']);

        // Takings ride beneath on their own scale: six units and 180,000 dinars
        // are not comparable quantities and never share an axis.
        $this->assertSame(__('Takings'), $trend['level']['name']);
    }

    public function test_a_service_has_no_trend_to_draw(): void
    {
        // A service is never in stock and holds no units.
        $service = Product::create([
            'name' => 'Repair', 'sku' => 'R1', 'unit' => 'job',
            'kind' => Product::KIND_SERVICE,
            'category_id' => Category::firstOrFail()->id,
            'purchase_price' => 0, 'sale_price' => 25_000, 'quantity' => 0,
        ]);

        $this->assertNull(
            $this->actingAs($this->admin)->get(route('products.show', $service))
                ->assertOk()->viewData('trend')
        );
    }
}
