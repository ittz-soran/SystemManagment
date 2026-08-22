<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Services\SecondHandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $status = $request->string('status')->toString() ?: 'in_stock';

        $items = Product::used()
            ->with('acquiredFrom', 'category')
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
            // What each one eventually sold for, so the list can show the profit
            // on the item rather than only what was paid for it.
            'sales' => $this->salesFor($items->getCollection()),
            'held' => (int) Product::used()->where('quantity', '>', 0)->count(),
            'heldValue' => (int) Product::used()->where('quantity', '>', 0)->sum('purchase_price'),
        ]);
    }

    public function create(): View
    {
        return view('second-hand.create', [
            'categories' => Category::orderBy('name')->get(),
            'defaultCategory' => Category::where('name', __(SecondHandService::DEFAULT_CATEGORY))->first(),
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
