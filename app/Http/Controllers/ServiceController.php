<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use App\Services\ProductCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $services = Product::services()
            ->with('category')
            ->when($request->filled('search'), fn ($q) => $q
                ->where('name', 'like', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        // What each has earned — the only number a service has, since it has no
        // stock to value and no cost to set against it.
        $earned = SaleItem::query()
            ->whereIn('product_id', $services->getCollection()->pluck('id'))
            ->selectRaw('product_id, SUM(quantity - quantity_returned) as units, SUM((quantity - quantity_returned) * unit_price) as revenue')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        return view('services.index', [
            'services' => $services,
            'earned' => $earned,
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

        $service->update($this->rules($request));

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
    private function rules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            // Section 2: IQD is whole numbers, never decimal.
            'sale_price' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function defaultCategory(): Category
    {
        return Category::firstOrCreate(['name' => __(self::DEFAULT_CATEGORY)]);
    }
}
