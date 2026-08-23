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

        $items = Product::used()
            // The batch, because the batch is what it cost. products.purchase_price
            // is a suggestion the product form can change; the batch is the money
            // that actually left the till, and is what FIFO charges the sale.
            ->with('acquiredFrom', 'category', 'stockBatches')
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

            'figures' => $this->figures(),
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
     * @return array<string, int>
     */
    private function figures(): array
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

        $month = [now()->startOfMonth(), now()->endOfMonth()];

        $soldThisMonth = (int) SaleItem::query()
            ->whereIn('product_id', $used->clone())
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', $month))
            ->sum(DB::raw('quantity - quantity_returned'));

        return [
            'held' => (int) $held->clone()->count(),
            'held_value' => $heldValue,
            'expected' => $asking - $heldValue,
            'sold_this_month' => $soldThisMonth,
            'made_this_month' => $this->profitBetween(...$month),
            'owed_to_sellers' => (int) Supplier::walkIns()->sum('balance'),
        ];
    }

    /**
     * What the second-hand trade made in a period.
     *
     * The same arithmetic as the profit report, narrowed to these items:
     * revenue from the lines, cost from the movements those lines consumed —
     * never from products.purchase_price — and both sides of a return taken
     * off, so an item sold and given back nets to nothing.
     */
    private function profitBetween(Carbon $from, Carbon $to): int
    {
        $used = Product::used()->select('id');

        $revenue = (int) SaleItem::query()
            ->whereIn('product_id', $used->clone())
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]))
            ->sum(DB::raw('(quantity - quantity_returned) * unit_price'));

        $cost = fn (string $reference, string $sign) => (int) StockMovement::query()
            ->whereIn('product_id', $used->clone())
            ->where('reference_type', $reference)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum(DB::raw($sign.'quantity * unit_cost'));

        return $revenue - ($cost(StockMovement::REF_SALE, '-') - $cost(StockMovement::REF_SALE_RETURN, ''));
    }

    public function create(): View
    {
        // Resolved rather than looked up: on a shop that upgraded into this
        // feature the category does not exist yet, and the first item would
        // land in whichever category happens to sort first.
        $default = Category::firstOrCreate(['name' => __(SecondHandService::DEFAULT_CATEGORY)]);

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
