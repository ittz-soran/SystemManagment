<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\SecondHandService;
use App\Support\TradeProfit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * The second-hand book: what the shop bought off the street, what it paid, what
 * it asked, and what it made.
 *
 * Each row is one physical thing, so the questions worth asking of the list are
 * about individual items rather than quantities — how long has this laptop been
 * sitting here, what did we make on that console.
 */
class SecondHandController extends Controller
{
    public function __construct(private SecondHandService $secondHand) {}

    public function index(Request $request): View
    {
        // Everything by default. This is a book, not a shelf: an item that has
        // been sold is the half of it worth reading — what it made — and
        // filtering it out meant a sale made the item disappear from the page
        // the moment it became interesting.
        $status = $request->string('status')->toString() ?: 'all';

        // The period the figures are read over, and the period the list is cut
        // to. Defaults to this month because that is what a shopkeeper checks,
        // but it is a range like any other rather than a fixed word in a label.
        $from = $request->date('from') ?: now()->startOfMonth();
        $to = ($request->date('to') ?: now())->endOfDay();

        $items = Product::used()
            // The batch, because the batch is what it cost. products.purchase_price
            // is a suggestion the product form can change; the batch is the money
            // that actually left the till, and is what FIFO charges the sale.
            ->with('acquiredFrom', 'category', 'stockBatches')
            // Bought in the period, or sold in it — either way it is part of
            // what happened between those dates.
            ->when($request->filled('from') || $request->filled('to'), fn ($q) => $q
                ->where(fn ($w) => $w
                    ->whereBetween('created_at', [$from, $to])
                    ->orWhereHas('saleItems.sale', fn ($sale) => $sale->whereBetween('sale_date', [$from, $to]))))
            ->when($status === 'in_stock', fn ($q) => $q->where('quantity', '>', 0))
            ->when($status === 'sold', fn ($q) => $q->where('quantity', '<=', 0))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->input('search').'%')
                ->orWhere('sku', 'like', '%'.$request->input('search').'%')
                ->orWhere('condition_note', 'like', '%'.$request->input('search').'%')))
            ->orderByDesc('id')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('second-hand.index', [
            'items' => $items,
            'status' => $status,
            // The two lines of an item's life: what it was bought on, and what
            // it was sold on. Loaded for the page, not per row.
            'purchases' => $this->purchasesFor($items->getCollection()),
            'sales' => $this->salesFor($items->getCollection()),
            // Counted for the filter, so it is plain where an item went rather
            // than looking like it was lost.
            'counts' => [
                'all' => (int) Product::used()->count(),
                'in_stock' => (int) Product::used()->where('quantity', '>', 0)->count(),
                'sold' => (int) Product::used()->where('quantity', '<=', 0)->count(),
            ],

            'figures' => $this->figures($from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * The numbers a second-hand book is kept for.
     *
     * Two are about now — how many things are sitting here and how much of the
     * shop's money is sitting in them — and two are about whether the trade is
     * worth doing: what this month's sales actually made, and what the shelf
     * would make if it cleared at the asking prices. The last is what is still
     * owed to the people the shop bought from, which is money that has not left
     * the till yet and is easy to forget.
     *
     * @return array<string, int|null>
     */
    private function figures(Carbon $from, Carbon $to): array
    {
        $used = Product::used()->select('id');
        $held = Product::used()->where('quantity', '>', 0);

        // Summed from the batches, like every other stock value in the system:
        // products.purchase_price is a suggestion the product form can change,
        // and this figure is the shop's money.
        $heldValue = (int) StockBatch::query()
            ->whereIn('product_id', $used->clone())
            ->sum(DB::raw('quantity_remaining * unit_cost'));

        // What the shelf would make at the prices on it. An expectation, not a
        // fact, and labelled as one.
        $asking = (int) $held->clone()->sum('sale_price');

        $bought = Product::used()->whereBetween('created_at', [$from, $to]);

        $sold = (int) SaleItem::query()
            ->whereIn('product_id', $used->clone())
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]))
            ->sum(DB::raw('quantity - quantity_returned'));

        $spent = (int) StockBatch::query()
            ->whereIn('product_id', $bought->clone()->select('id'))
            ->sum(DB::raw('quantity_in * unit_cost'));

        $trade = TradeProfit::between(Product::used(), $from, $to);

        /*
         * Every figure here that is a cost, or is worked out from one, as this
         * reader is allowed to see it — null when they may not.
         *
         * A total next to a count is a unit cost one division away: "money tied
         * up" over "items held" is what each one cost. So it is not enough to
         * mask the price on a row and leave the totals standing, and it is not
         * enough to mask the totals either — the profits have to be worked out
         * from the cost the reader sees, or subtracting one from the other
         * gives back the real figure.
         */
        $heldValue = cost_seen($heldValue);
        $spent = cost_seen($spent);
        $tradeCost = cost_seen($trade['cost']);

        return [
            // Where things stand, whatever period is being read.
            'held' => (int) $held->clone()->count(),
            'held_value' => $heldValue,
            'expected' => $heldValue === null ? null : $asking - $heldValue,

            // And what happened in the period.
            'bought' => (int) $bought->count(),
            'spent' => $spent,
            'sold' => $sold,
            'made' => $tradeCost === null ? null : $trade['revenue'] - $tradeCost,
            'owed_to_sellers' => (int) Supplier::walkIns()->sum('balance'),
        ];
    }

    public function create(): View
    {
        // Resolved rather than looked up: on a shop that upgraded into this
        // feature the category does not exist yet, and the first item would
        // land in whichever category happens to sort first.
        $default = Category::firstOrCreate(['name' => SecondHandService::DEFAULT_CATEGORY]);

        return view('second-hand.create', [
            'categories' => Category::orderBy('name')->get(),
            'defaultCategory' => $default,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'condition_note' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'unit' => ['nullable', 'string', 'max:32'],

            // Section 2: IQD is whole numbers, never decimal.
            'cost' => ['required', 'integer', 'min:0'],
            'sale_price' => ['required', 'integer', 'min:0'],
            'amount_paid' => ['nullable', 'integer', 'min:0', 'lte:cost'],
            'payment_method' => ['nullable', 'in:cash,bank,transfer'],

            'seller_name' => ['required', 'string', 'max:255'],
            'seller_phone' => ['nullable', 'string', 'max:32'],
            // Set only when the shopkeeper picked somebody from the list. Blank
            // means a new person, whatever the name happens to match.
            'seller_id' => ['nullable', 'integer', 'exists:suppliers,id'],

            'bought_at' => ['required', 'date'],
        ], [
            'amount_paid.lte' => __('Paid amount cannot exceed the price agreed.'),
        ]);

        try {
            $result = $this->secondHand->buy(
                input: $data,
                user: $request->user(),
                boughtAt: Carbon::parse($data['bought_at']),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('second-hand.index')
            ->with('success', __(':item bought from :seller', [
                'item' => $result['product']->name,
                'seller' => $result['seller']->name,
            ]));
    }

    /**
     * The sale line each item was eventually sold on, keyed by product.
     *
     * One query for the page rather than one per row. An item can be sold, come
     * back and be sold again, so the most recent line is the one that counts.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $items
     * @return \Illuminate\Support\Collection<int, SaleItem>
     */
    private function salesFor($items)
    {
        return SaleItem::with('sale')
            ->whereIn('product_id', $items->pluck('id'))
            ->orderBy('id')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * The purchase line each item came in on, keyed by product.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $items
     * @return \Illuminate\Support\Collection<int, PurchaseItem>
     */
    private function purchasesFor($items)
    {
        return PurchaseItem::with('purchase')
            ->whereIn('product_id', $items->pluck('id'))
            ->orderBy('id')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * The people already bought from, as the shopkeeper types.
     *
     * Searched by name and by number, because the number is what somebody gives
     * twice the same way while a name gets spelled three ways by three hands.
     * Nothing here matches anybody automatically — it only offers, and the
     * shopkeeper picks.
     */
    public function sellerSearch(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();

        if ($term === '') {
            return response()->json([]);
        }

        return response()->json(
            Supplier::walkIns()
                ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orderBy('name')
                ->limit(10)
                ->get()
                ->map(fn (Supplier $seller) => [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'phone' => $seller->phone,
                    // What the shop still owes them, so the shopkeeper can see
                    // an outstanding balance before agreeing another price.
                    'balance' => (int) $seller->balance,
                    'balance_label' => $seller->balance > 0
                        ? __('You still owe :amount', ['amount' => money($seller->balance)])
                        : null,
                ]),
        );
    }

    /** The walk-in sellers, kept off the supplier list but reachable. */
    public function sellers(Request $request): View
    {
        return view('second-hand.sellers', [
            'sellers' => Supplier::walkIns()
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('phone', 'like', '%'.$request->input('search').'%')))
                ->orderByDesc('balance')
                ->orderBy('name')
                ->paginate($request->user()->items_per_page)
                ->withQueryString(),
            'owed' => (int) Supplier::walkIns()->sum('balance'),
        ]);
    }
}
