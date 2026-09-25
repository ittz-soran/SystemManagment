<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\StockBatch;
use App\Services\ExchangeService;
use App\Services\PurchaseReturnService;
use App\Services\SaleReturnService;
use App\Services\SwapService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * One counter for everything that comes back — Soran, 2026-09-25.
 *
 * *"i want one page for all but at deferent document number PRT, SRT, SWP or
 * any ... open page -> select item (by smart search) -> show swap because
 * faulty, return from customer, return to supplier"*.
 *
 * ⚠️ **THIS PAGE HAS NO DOCUMENT NUMBER OF ITS OWN.** It issues `SWP`, `SRT`,
 * `PRT` — or the pair of them the answer needs — from the counters that
 * already exist, and nothing is written until a button is held. So a
 * shopkeeper can look at all three answers and walk away having changed
 * nothing.
 *
 * ⚠️ **A product alone is not enough to act on, which is why there is a middle
 * step.** A sale return refunds THAT invoice line's price and puts stock back
 * in THAT line's batches; a purchase return comes off THAT purchase's batch.
 * So the search finds the product and the page then asks which paper it is
 * on — and it can offer both kinds at once, because the answer decides which
 * of the three documents is even possible.
 */
class GoodsBackController extends Controller
{
    /**
     * How far back the sold list reaches — Soran, 2026-09-25: *"last 25"*.
     *
     * A faulty item coming back is far more likely to be last week's sale than
     * one from two years ago, and a shopkeeper should not page through a
     * history to find it.
     */
    private const RECENT = 25;

    public function __construct(
        private SwapService $swaps,
        private SaleReturnService $returns,
        private PurchaseReturnService $purchaseReturns,
        private ExchangeService $exchanges,
    ) {}

    /**
     * The page, in whichever of its three states the reader has reached.
     *
     * Nothing chosen → find the item. A product → which paper it is on. A line
     * → what can be done about it, with the shelf and the batch already read.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $line = $request->filled('sale_item')
            ? SaleItem::with('sale.customer', 'sale.items', 'product')->find($request->integer('sale_item'))
            : null;

        $bought = $request->filled('purchase_item')
            ? PurchaseItem::with('purchase.supplier', 'product')->find($request->integer('purchase_item'))
            : null;

        $product = match (true) {
            $line !== null => $line->product,
            $bought !== null => $bought->product,
            $request->filled('product') => Product::find($request->integer('product')),
            default => null,
        };

        return view('goods-back.index', [
            'lens' => $user->lens(),
            'term' => $request->string('q')->trim()->toString(),
            'products' => $this->matching($request),
            'product' => $product,
            'line' => $line,
            'bought' => $bought,

            // The middle step. Skipped once a line is chosen — the page is
            // already past the question these answer.
            'soldLines' => $product === null || $line || $bought ? collect() : $this->soldLines($product),
            'boughtLines' => $product === null || $line || $bought ? collect() : $this->boughtLines($product),

            // Everything the three cards need to state their consequence in
            // money before anything is done.
            'state' => $line ? $this->soldState($line) : ($bought ? $this->boughtState($bought) : []),

            /*
             * The exchange's own sub-step: which product is he taking instead.
             * Chosen by following a link rather than by script, the same as
             * everything else on this page — and the server has to read the
             * chosen product back anyway, for its price and what is on the
             * shelf.
             */
            'wanted' => $line && $request->filled('wanted')
                ? Product::where('id', $request->integer('wanted'))->where('id', '!=', $line->product_id)->first()
                : null,
            'wantedTerm' => $request->string('w')->trim()->toString(),
            'wantedProducts' => $line && ! $request->filled('wanted')
                ? $this->wantedProducts($request, $line)
                : collect(),

            'may' => [
                'swap' => $user->hasPermission('swaps.create'),
                'refund' => $user->hasPermission('sale_returns.create'),
                'sendBack' => $user->hasPermission('purchase_returns.create'),
                // An exchange writes both, so it needs both keys.
                'exchange' => $user->hasPermission('sale_returns.create') && $user->hasPermission('sales.create'),
            ],
        ]);
    }

    /**
     * What the box thinks you might mean, while you type.
     *
     * ⚠️ **The suggestion answers the question before you choose it.** Each row
     * carries what is actually possible with that product — *"3 sold can come
     * back · 12 bought can go back"* — so a shopkeeper who scans the wrong
     * thing learns it from the list rather than from two clicks further in.
     */
    public function suggest(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();

        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }

        $products = Product::whereIn('kind', [Product::KIND_STOCK, Product::KIND_USED])
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(8)
            ->get();

        if ($products->isEmpty()) {
            return response()->json(['groups' => []]);
        }

        $sold = SaleItem::whereIn('product_id', $products->pluck('id'))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($lines) => $lines->sum(fn (SaleItem $l) => $l->returnableQuantity()));

        $bought = PurchaseItem::whereIn('product_id', $products->pluck('id'))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($lines) => $lines->sum(fn (PurchaseItem $l) => $l->returnableQuantity()));

        return response()->json(['groups' => [[
            'label' => __('Products'),
            'items' => $products->map(fn (Product $p) => [
                'url' => route('goods-back.index', ['product' => $p->id]),
                'icon' => 'box-seam',
                'label' => $p->name,
                'note' => $this->whatIsPossible($p, (int) ($sold[$p->id] ?? 0), (int) ($bought[$p->id] ?? 0)),
            ])->all(),
        ]]]);
    }

    private function whatIsPossible(Product $product, int $sold, int $bought): string
    {
        $bits = [$product->sku];

        if ($sold > 0) {
            $bits[] = __(':count sold can come back', ['count' => number_format($sold)]);
        }

        if ($bought > 0) {
            $bits[] = __(':count bought can go back', ['count' => number_format($bought)]);
        }

        if ($sold < 1 && $bought < 1) {
            $bits[] = __('nothing to act on');
        }

        return implode(' · ', $bits);
    }

    /** The same thing again. The invoice is not touched. */
    public function swap(Request $request): RedirectResponse
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

    /** Something different. The lines change, because they have to. */
    public function exchange(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sale_item_id' => ['required', 'exists:sale_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'exists:products,id'],
            'wanted_quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'integer', 'min:0'],
            'amount_paid' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'faulty' => ['nullable', 'boolean'],
        ]);

        try {
            $done = $this->exchanges->create(
                saleItem: SaleItem::findOrFail($data['sale_item_id']),
                quantity: (int) $data['quantity'],
                wanted: Product::findOrFail($data['product_id']),
                wantedQuantity: (int) $data['wanted_quantity'],
                wantedPrice: (int) $data['unit_price'],
                user: $request->user(),
                on: Carbon::today(),
                paidNow: (int) ($data['amount_paid'] ?? 0),
                paymentMethod: $data['payment_method'],
                faulty: (bool) ($data['faulty'] ?? false),
            );
        } catch (RuntimeException|Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $done['sale'])
            ->with('success', __('Exchanged on :sale, taken back on :return', [
                'sale' => $done['sale']->document_no,
                'return' => $done['return']->document_no,
            ]));
    }

    /** His money back. A plain sale return, and a supplier return if faulty. */
    public function refund(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sale_item_id' => ['required', 'exists:sale_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'faulty' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $line = SaleItem::with('sale')->findOrFail($data['sale_item_id']);

        try {
            $return = $this->returns->create(
                sale: $line->sale,
                lines: [['sale_item_id' => $line->id, 'quantity' => (int) $data['quantity']]],
                user: $request->user(),
                returnDate: Carbon::today(),
                reason: $data['reason'] ?? null,
                paymentMethod: $data['payment_method'],
                faultyLines: ($data['faulty'] ?? false) ? [$line->id] : [],
            );
        } catch (RuntimeException|Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sale-returns.show', $return)
            ->with('success', __('Taken back on :number', ['number' => $return->document_no]));
    }

    /** Back to the supplier it was bought from. No customer, no invoice. */
    public function sendBack(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'purchase_item_id' => ['required', 'exists:purchase_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $item = PurchaseItem::with('purchase')->findOrFail($data['purchase_item_id']);

        try {
            $return = $this->purchaseReturns->create(
                purchase: $item->purchase,
                lines: [['purchase_item_id' => $item->id, 'quantity' => (int) $data['quantity']]],
                user: $request->user(),
                returnDate: Carbon::today(),
                reason: $data['reason'] ?? null,
                paymentMethod: $data['payment_method'],
            );
        } catch (RuntimeException|Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-returns.show', $return)
            ->with('success', __('Sent back on :number', ['number' => $return->document_no]));
    }

    /**
     * Products matching what was typed — the same three things a scanner or a
     * shopkeeper reaches for.
     *
     * ⚠️ **Stock only.** Soran, 2026-09-25: *"no service"*. A repair or a
     * fitting fee has no stock to move, so two of the three answers could never
     * apply to it and the third is the sale return screen's own job.
     *
     * @return Collection<int, Product>
     */
    private function matching(Request $request)
    {
        if (! $request->filled('q') || $request->filled('product')
            || $request->filled('sale_item') || $request->filled('purchase_item')) {
            return Product::query()->whereRaw('1 = 0')->get();
        }

        $term = $request->string('q')->trim()->toString();

        return Product::whereIn('kind', [Product::KIND_STOCK, Product::KIND_USED])
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /**
     * What he could take instead.
     *
     * ⚠️ **Never the product he is bringing back.** That is a swap, and
     * offering it here would write two documents to say what one says better —
     * and change an invoice that had no reason to change. `ExchangeService`
     * refuses it as well, because a URL can be typed.
     *
     * @return Collection<int, Product>
     */
    private function wantedProducts(Request $request, SaleItem $line)
    {
        if (! $request->filled('w')) {
            return Product::query()->whereRaw('1 = 0')->get();
        }

        $term = $request->string('w')->trim()->toString();

        return Product::whereIn('kind', [Product::KIND_STOCK, Product::KIND_USED])
            ->where('id', '!=', $line->product_id)
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /**
     * Invoice lines that sold this product and still have one to come back.
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
            ->sortByDesc(fn (SaleItem $item) => [$item->sale->sale_date->timestamp, $item->id])
            ->take(self::RECENT)
            ->values();
    }

    /**
     * Purchase lines whose units are still in their batch.
     *
     * ⚠️ **Two different caps, and the smaller one wins.** A purchase line
     * counts what has been sent back already; the batch counts what is still
     * physically there. A line bought ten and sold ten has nothing to return
     * although its own counter still says ten — so the row carries both and
     * says which one bit.
     *
     * @return Collection<int, PurchaseItem>
     */
    private function boughtLines(Product $product)
    {
        $items = PurchaseItem::with('purchase.supplier')
            ->where('product_id', $product->id)
            ->get()
            ->sortByDesc(fn (PurchaseItem $item) => [$item->purchase->purchase_date->timestamp, $item->id])
            ->take(self::RECENT)
            ->values();

        $batches = StockBatch::whereIn('purchase_item_id', $items->pluck('id'))
            ->get()
            ->keyBy('purchase_item_id');

        return $items->each(function (PurchaseItem $item) use ($batches) {
            $inBatch = (int) ($batches[$item->id]->quantity_remaining ?? 0);

            $item->setAttribute('can_go_back', min($item->returnableQuantity(), $inBatch));
            $item->setAttribute('in_batch', $inBatch);
            $item->setAttribute('batch_no', $batches[$item->id]->id ?? null);
        });
    }

    /**
     * Everything the three answers need to say what they will do.
     *
     * @return array<string, mixed>
     */
    private function soldState(SaleItem $line): array
    {
        $product = $line->product;
        $canComeBack = $line->returnableQuantity();
        $onShelf = $product->tracksStock() ? (int) $product->quantity : 0;

        // What the next unit off the shelf costs — the other half of what a
        // swap is worth, since the supplier only refunds what they were paid.
        $replacement = $product->tracksStock()
            ? (int) ($product->stockBatches()->withStock()->fifoOrder()->value('unit_cost') ?? 0)
            : 0;

        $origins = $canComeBack > 0
            ? $this->returns->originsFor($line, min($canComeBack, max(1, $canComeBack)))
            : collect();

        return [
            'canComeBack' => $canComeBack,
            'onShelf' => $onShelf,
            'mostToSwap' => min($canComeBack, $onShelf),
            'replacementCost' => $replacement,
            'lineCost' => (int) ($origins->first()->unit_cost ?? 0),

            // Section 8's shape: allowed, or the sentence that says why not.
            'swapState' => $this->swaps->canSwap($line, 1),

            // Who would be billed if the faulty box is ticked, and null when
            // these units came from opening stock with no supplier behind them.
            'supplier' => $origins->first()?->purchase_item?->purchase?->supplier?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function boughtState(PurchaseItem $bought): array
    {
        $batch = StockBatch::where('purchase_item_id', $bought->id)->first();
        $inBatch = (int) ($batch->quantity_remaining ?? 0);

        return [
            'canGoBack' => min($bought->returnableQuantity(), $inBatch),
            'inBatch' => $inBatch,
            'stillOnTheLine' => $bought->returnableQuantity(),
            'batchNo' => $batch?->id,
            'owedOnThePurchase' => $bought->purchase->amountDue(),
        ];
    }
}
