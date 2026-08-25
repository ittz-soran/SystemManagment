<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\LabelPrinter;
use App\Services\LabelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function show(Product $product): View
    {
        return view('products.show', [
            'product' => $product->load('category'),
            'batches' => $product->stockBatches()->with('source')->fifoOrder()->get(),
            'movements' => StockMovement::where('product_id', $product->id)
                ->with(['batch', 'reference'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('sequence')
                ->limit(100)
                ->get(),

            // Section 4: the label screen's defaults come from settings, and the
            // modal lets them be changed for this one print run.
            'labelSizes' => config('labels.sizes'),
            'labelFields' => app(LabelService::class)->fields(),
            'labelPrinter' => app(LabelPrinter::class),

            // A second-hand item's life is two lines long: bought on one
            // document, sold on another. Loaded here so its page can say so
            // without the reader going to look for them.
            'boughtOn' => $product->isUsed()
                ? PurchaseItem::with('purchase')->where('product_id', $product->id)->orderBy('id')->first()
                : null,
            'soldOn' => $product->isUsed()
                ? SaleItem::with('sale')->where('product_id', $product->id)->orderByDesc('id')->first()
                : null,
        ]);
    }

    public function destroy(Product $product): RedirectResponse
    {
        // Section 5: products with history are deactivated, never deleted.
        if ($product->stockMovements()->exists()) {
            $product->update(['is_active' => false]);

            return back()->with('success', __('This product has stock history, so it was deactivated instead of deleted.'));
        }

        $product->delete();

        return redirect()
            ->to(after_delete(route('products.show', $product), route('products.index')))
            ->with('success', __('Product deleted'));
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

        // Which kinds the caller can use. A sale can carry anything the shop
        // sells; a purchase and an adjustment can only touch ordinary stock,
        // since a service has no stock and a second-hand item is bought once,
        // through its own screen, and would otherwise gain a second batch for a
        // machine there is only one of.
        $kinds = $request->filled('kinds')
            ? array_intersect(
                explode(',', $request->string('kinds')->toString()),
                [Product::KIND_STOCK, Product::KIND_USED, Product::KIND_SERVICE],
            )
            : [Product::KIND_STOCK, Product::KIND_USED, Product::KIND_SERVICE];

        $exact = Product::active()
            ->ofKind($kinds)
            ->where(fn ($q) => $q->where('barcode', $term)->orWhere('sku', $term))
            ->first();

        $products = $exact
            ? collect([$exact])
            : Product::active()->ofKind($kinds)
                ->where('name', 'like', '%'.$term.'%')->orderBy('name')->limit(15)->get();

        return response()->json([
            'exact' => (bool) $exact,
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'kind' => $p->kind,
                'condition_note' => $p->condition_note,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'unit' => $p->unit,
                'quantity' => $p->quantity,
                'sale_price' => $p->sale_price,
                'purchase_price' => $p->purchase_price,
                // Section 9b: the below-cost warning needs the cost of the batch
                // that would actually be consumed next.
                // A service has no batch and no cost, so there is nothing it can
                // be sold below.
                'next_batch_cost' => $p->tracksStock()
                    ? $p->stockBatches()->withStock()->fifoOrder()->value('unit_cost')
                    : null,
            ]),
        ]);
    }
}
