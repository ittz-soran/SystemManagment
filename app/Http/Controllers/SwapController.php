<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Swap;
use App\Services\SaleReturnService;
use App\Services\SwapService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * One page for a faulty item coming back — Soran, 2026-09-23.
 *
 * *"just select product and do swap and system read stock and let user as
 * option swap same if available or change to other or refund"*.
 *
 * ⚠️ **Three outcomes, and only one of them is new.** Handing over the same
 * product is the `swaps` document, because it must leave the invoice alone.
 * Giving something different, or the money back, is a sale return — already
 * built, already sends the faulty unit to its supplier — so this page routes
 * there rather than growing a second copy of it.
 */
class SwapController extends Controller
{
    public function __construct(
        private SwapService $swaps,
        private SaleReturnService $returns,
    ) {}

    public function index(Request $request): View
    {
        return view('swaps.index', [
            'lens' => $request->user()->lens(),
            'swaps' => Swap::with('sale.customer', 'product', 'purchaseReturn')
                ->orderByDesc('swapped_at')->orderByDesc('id')
                ->paginate($request->user()->items_per_page),
        ]);
    }

    /**
     * The page, in whichever of its three states the reader has reached.
     *
     * Nothing chosen → find the product. A product → which invoice sold it.
     * A line → what the shop can do about it, with the shelf already read.
     */
    public function create(Request $request): View
    {
        $product = $request->filled('product')
            ? Product::find($request->integer('product'))
            : null;

        $line = $request->filled('sale_item')
            ? SaleItem::with('sale.customer', 'product')->find($request->integer('sale_item'))
            : null;

        if ($line !== null) {
            $product = $line->product;
        }

        return view('swaps.create', [
            'lens' => $request->user()->lens(),
            'term' => $request->string('q')->trim()->toString(),
            'products' => $this->matching($request),
            'product' => $product,
            'line' => $line,

            // Which invoices sold it and still have something to give back.
            'lines' => $product === null || $line !== null ? collect() : $this->soldLines($product),

            /*
             * ⚠️ Where the faulty unit came from, so the page can say who will
             * carry the cost before anything is done — the same trace the
             * faulty sale return uses.
             */
            'origins' => $line === null || $line->returnableQuantity() < 1
                ? collect()
                : $this->returns->originsFor($line, $line->returnableQuantity()),

            // Giving something different or the money back is a sale return,
            // and that is its own permission.
            'mayReturn' => $request->user()->hasPermission('sale_returns.create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sale_item_id' => ['required', 'exists:sale_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $swap = $this->swaps->create(
                saleItem: SaleItem::findOrFail($data['sale_item_id']),
                quantity: (int) $data['quantity'],
                user: $request->user(),
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException|Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('swaps.show', $swap)
            ->with('success', __('Swapped on :number', ['number' => $swap->document_no]));
    }

    public function show(Request $request, Swap $swap): View
    {
        return view('swaps.show', [
            'lens' => $request->user()->lens(),
            'swap' => $swap->load('sale.customer', 'saleItem', 'product', 'purchaseReturn.purchase.supplier', 'user'),
        ]);
    }

    /**
     * Products matching what was typed — the same three things a scanner or a
     * shopkeeper reaches for.
     *
     * @return Collection<int, Product>
     */
    private function matching(Request $request)
    {
        if (! $request->filled('q') || $request->filled('product') || $request->filled('sale_item')) {
            return Product::query()->whereRaw('1 = 0')->get();
        }

        $term = $request->string('q')->trim()->toString();

        return Product::where('kind', Product::KIND_STOCK)
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /**
     * The invoice lines that sold this product and still have one to give back.
     *
     * ⚠️ Newest first: a faulty item coming back is far more likely to be last
     * week's sale than one from two years ago, and the shopkeeper should not
     * have to page past a history to find it.
     *
     * @return Collection<int, SaleItem>
     */
    private function soldLines(Product $product)
    {
        return SaleItem::with('sale.customer')
            ->where('product_id', $product->id)
            ->whereColumn('quantity', '>', 'quantity_returned')
            ->get()
            ->filter(fn (SaleItem $item) => $item->returnableQuantity() > 0)
            ->sortByDesc(fn (SaleItem $item) => $item->sale->sale_date)
            ->take(25)
            ->values();
    }
}
