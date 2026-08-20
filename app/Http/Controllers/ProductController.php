<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\ProductCodeService;
use App\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(
        private ProductCodeService $codes,
        private StockAdjustmentService $adjustments,
    ) {}

    public function index(Request $request): View
    {
        $products = Product::with('category')
            ->when($request->filled('search'), fn ($q) => $q->search($request->string('search')->toString()))
            // Section 9: filtering by SEVERAL categories at once is
            // WHERE category_id IN (...) — no pivot table needed.
            ->when($request->filled('categories'), fn ($q) => $q->whereIn('category_id', $request->array('categories')))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereColumn('quantity', '<=', DB::raw('COALESCE(reorder_level, '.(int) setting('low_stock_threshold', 0).')')))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('products.create', [
            'product' => new Product(['unit' => 'pcs', 'is_active' => true, 'purchase_price' => 0, 'sale_price' => 0]),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request) {
            // Codes are generated inside the transaction so the counters and the
            // product row commit together.
            $codes = $this->codes->resolve($request->only('sku', 'barcode'));

            $product = Product::create([
                ...$request->safe()->except(['sku', 'barcode', 'opening_quantity', 'opening_unit_cost']),
                'sku' => $codes['sku'],
                'barcode' => $codes['barcode'],
                'quantity' => 0,
                'is_active' => $request->boolean('is_active', true),
            ]);

            // Section 5: opening stock becomes the product's first FIFO layer.
            // Section 4 allows only `purchase` or `adjustment` as a batch source,
            // so this is an `in` adjustment (see Section 13).
            if ($request->integer('opening_quantity') > 0) {
                $this->adjustments->recordOpeningStock(
                    product: $product,
                    quantity: $request->integer('opening_quantity'),
                    unitCost: $request->integer('opening_unit_cost'),
                    user: $request->user(),
                );
            }

            return $product;
        });

        return redirect()
            ->route('products.index')
            ->with('success', __('Product saved'));
    }

    public function show(Product $product): View
    {
        return view('products.show', [
            'product' => $product->load('category'),
            'batches' => $product->stockBatches()->fifoOrder()->get(),
            'movements' => StockMovement::where('product_id', $product->id)
                ->with('batch')
                ->orderByDesc('occurred_at')
                ->orderByDesc('sequence')
                ->limit(100)
                ->get(),
        ]);
    }

    public function edit(Product $product): View
    {
        return view('products.edit', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        DB::transaction(function () use ($request, $product) {
            $codes = $this->codes->resolve($request->only('sku', 'barcode'));

            $product->update([
                ...$request->safe()->except(['sku', 'barcode', 'opening_quantity', 'opening_unit_cost']),
                'sku' => $codes['sku'],
                'barcode' => $codes['barcode'],
                'is_active' => $request->boolean('is_active'),
            ]);
        });

        return redirect()
            ->route('products.index')
            ->with('success', __('Product saved'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        // Section 5: products with history are deactivated, never deleted.
        if ($product->stockMovements()->exists()) {
            $product->update(['is_active' => false]);

            return back()->with('success', __('This product has stock history, so it was deactivated instead of deleted.'));
        }

        $product->delete();

        return back()->with('success', __('Product deleted'));
    }

    /**
     * Section 9b: the cart's lookup. Scanning searches barcode first, then sku,
     * then name — an exact code match returns a single row so the cart can add
     * it straight away.
     */
    public function search(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();

        if ($term === '') {
            return response()->json([]);
        }

        $exact = Product::active()
            ->where(fn ($q) => $q->where('barcode', $term)->orWhere('sku', $term))
            ->first();

        $products = $exact
            ? collect([$exact])
            : Product::active()->where('name', 'like', '%'.$term.'%')->orderBy('name')->limit(15)->get();

        return response()->json([
            'exact' => (bool) $exact,
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'unit' => $p->unit,
                'quantity' => $p->quantity,
                'sale_price' => $p->sale_price,
                'purchase_price' => $p->purchase_price,
                // Section 9b: the below-cost warning needs the cost of the batch
                // that would actually be consumed next.
                'next_batch_cost' => $p->stockBatches()->withStock()->fifoOrder()->value('unit_cost'),
            ]),
        ]);
    }
}
