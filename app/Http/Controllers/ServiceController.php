<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use App\Rules\Amount;
use App\Services\ProductCodeService;
use App\Support\MoneyInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The things the shop does rather than sells: setting up an email account,
 * installing something, transferring data.
 *
 * A service costs nothing to provide, so the whole price is profit. That is not
 * a rule written anywhere in the arithmetic — it falls out of the fact that a
 * service opens no batch and writes no movement, and COGS is the sum of the
 * movements a sale consumed. Revenue with nothing against it.
 */
class ServiceController extends Controller
{
    public function __construct(private ProductCodeService $codes) {}

    /** The category a service falls into when none is chosen. */
    public const DEFAULT_CATEGORY = 'Services';

    public function index(Request $request): View
    {
        /*
         * The period the Sold and Earned columns are read over. Defaults to
         * this month because that is what a shopkeeper checks — the same
         * default, and the same two boxes, as the second-hand book and the
         * profit report. "All time" is one link away rather than the silent
         * default it used to be.
         */
        $all = $request->boolean('all');
        $from = $all ? Carbon::create(1970, 1, 1)->startOfDay() : ($request->date('from') ?: now()->startOfMonth());
        $to = $all ? now()->endOfDay() : ($request->date('to') ?: now())->endOfDay();

        $services = Product::services()
            ->with('category')
            ->when($request->filled('search'), fn ($q) => $q
                ->where('name', 'like', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        /*
         * ⚠️ **OVER A PERIOD, AND THE PERIOD IS ON THE SCREEN** — Soran,
         * 2026-09-25: *"in services total show 290,000 ... in reports show
         * 231,000 service !! that is wrong"*.
         *
         * Neither figure was wrong. This column totalled ALL TIME while the
         * profit report totalled THIS MONTH, and neither page said so — two
         * true answers to two different questions, printed as though they
         * answered the same one. From the outside that is indistinguishable
         * from a broken till, and it is worse than being wrong, because a
         * wrong number can be corrected and a mistrusted one cannot.
         *
         * So it takes a range like the second-hand book and the reports do,
         * defaults to this month like both of them, and prints the dates above
         * the table. `AccountingAgreesTest` now holds the two to each other.
         */
        $earned = SaleItem::query()
            ->whereIn('product_id', $services->getCollection()->pluck('id'))
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]))
            ->selectRaw('product_id, SUM(quantity - quantity_returned) as units, SUM((quantity - quantity_returned) * unit_price) as revenue')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        return view('services.index', [
            // Section 2b — the price box takes this currency, and the prices in
            // the table are read in it.
            'lens' => $request->user()->lens(),
            'services' => $services,
            'earned' => $earned,
            'from' => $from,
            'to' => $to,
            'all' => $all,
            'categories' => Category::orderBy('name')->get(),
            // Same reasoning as the second-hand screen: on a shop that upgraded
            // into this feature the category does not exist until the first
            // service is saved, and the modal would default to whichever
            // category sorts first.
            'defaultCategory' => $this->defaultCategory(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->rules($request);

        DB::transaction(function () use ($data) {
            Product::create([
                ...$data,
                'kind' => Product::KIND_SERVICE,
                'sku' => $this->codes->resolve([])['sku'],
                // No barcode: nothing is ever scanned off a service.
                'barcode' => null,
                'category_id' => $data['category_id'] ?? $this->defaultCategory()->id,
                'unit' => 'each',
                // Section 4: a cache of the batches, and a service has none.
                'quantity' => 0,
                'purchase_price' => 0,
                'reorder_level' => 0,
            ]);
        });

        return back()->with('success', __('Service saved'));
    }

    public function update(Request $request, Product $service): RedirectResponse
    {
        abort_unless($service->isService(), 404);

        $service->update($this->rules($request, $service));

        return back()->with('success', __('Service saved'));
    }

    public function destroy(Product $service): RedirectResponse
    {
        abort_unless($service->isService(), 404);

        // Section 4: a service already sold stays on its sales, so it is
        // deactivated rather than removed if it has ever been used.
        if (SaleItem::where('product_id', $service->id)->exists()) {
            $service->forceFill(['is_active' => false])->save();

            return back()->with('success', __('Service deactivated — it stays on the sales it was sold on.'));
        }

        $service->delete();

        return back()->with('success', __('Service deleted'));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?Product $service = null): array
    {
        $lens = $request->user()->lens();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            // Section 2: what is stored is whole base-currency units, never
            // decimal. Section 2b: what is TYPED may be another currency.
            'sale_price' => ['required', new Amount($lens, min: 0)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // ⚠️ With the service's own price as the original, so renaming one
        // through a dollar lens does not move its price by a rounding.
        $data['sale_price'] = MoneyInput::fromRequest(
            $request, 'sale_price', $lens, $service?->sale_price,
        );

        return $data;
    }

    private function defaultCategory(): Category
    {
        // Not translated, for the reason SecondHandService gives: a stored
        // name read through __() makes a second row per language.
        return Category::firstOrCreate(['name' => self::DEFAULT_CATEGORY]);
    }
}
