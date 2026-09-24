<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Support\Digits;
use App\Support\TradeProfit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One box, and everything the shop knows about the answer — Soran, 2026-09-24.
 *
 * *"create an new page are user just can search… for example I searched
 * PD-17-UK auto show invoices, purchases, statistics, best supplier buy from
 * and customer, and actions like sale or purchase or return"*.
 *
 * ⚠️ **This is not the product page, and it is not the search dropdown.** The
 * product page is the *record* — batches, movements, ninety days of trend. The
 * dropdown is a *jump* — type a number, land on the document. This page answers
 * the question actually asked at the counter, with a customer standing there
 * holding something: *what do I know about this, and what can I do about it
 * right now.* Hence the statistics, the two people the shop deals with most
 * over it, the invoices that sold it, and a row of buttons.
 *
 * ⚠️ **Nothing here is shown that its own screen would withhold.** A panel is
 * behind the permission of the screen it summarises — a reader without
 * `purchases.view` gets no purchases, no best supplier and no spend, because a
 * page that says "you bought 40 of these for 1,600,000" has told them what the
 * purchases screen was keeping from them.
 */
class FindController extends Controller
{
    /** Enough to answer the question, few enough to read on a phone. */
    private const RECENT = 8;

    /** More than this and the reader should narrow the term, not scroll. */
    private const CANDIDATES = 12;

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        // ⚠️ Before anything is matched. A keyboard left on Kurdish types
        // INV-٠٠٠٠٥, and every LIKE below would miss it.
        $term = Digits::english($request->string('q')->trim()->toString());

        $product = $this->subject($request, $user, $term);

        return view('find.index', [
            'lens' => $user->lens(),
            'term' => $term,
            'product' => $product,

            // The candidates are only worth gathering when nothing resolved.
            'products' => $product !== null ? collect() : $this->products($user, $term),
            'people' => $product !== null ? collect() : $this->people($user, $term),
            'documents' => $product !== null ? collect() : $this->documents($user, $term),

            ...($product === null ? [] : $this->dossier($user, $product)),
        ]);
    }

    /**
     * The one product this page is about, when there is one.
     *
     * Either the reader picked it from a list of candidates, or the term left
     * no room for doubt — a scanned barcode, or a name only one product has.
     */
    private function subject(Request $request, User $user, string $term): ?Product
    {
        if (! $user->hasPermission('products.view')) {
            return null;
        }

        if ($request->filled('product')) {
            return Product::with('category')->find($request->integer('product'));
        }

        if ($term === '') {
            return null;
        }

        $matches = $this->matching($term)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first()->load('category') : null;
    }

    /**
     * Products matching what was typed.
     *
     * ⚠️ The sale price is matched and the purchase price is not. *"search by
     * all data type like name, sku, barcode, prices"* — but one of those two
     * prices is what the shop paid, and a box that answers "which of these did
     * I pay 40,000 for" has handed the cost to anybody who can type a number.
     * The price a customer is charged is on the shelf edge already.
     */
    private function matching(string $term): Builder
    {
        $query = Product::query()->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%");

            if (ctype_digit($term)) {
                $q->orWhere('sale_price', (int) $term);
            }
        });

        // A scanned code is one row, and it belongs at the top of it.
        return $query->orderByRaw('CASE WHEN barcode = ? OR sku = ? THEN 0 ELSE 1 END', [$term, $term])
            ->orderBy('name');
    }

    /** @return Collection<int, Product> */
    private function products(User $user, string $term): Collection
    {
        if ($term === '' || ! $user->hasPermission('products.view')) {
            return collect();
        }

        return $this->matching($term)->limit(self::CANDIDATES)->get();
    }

    /**
     * Customers and suppliers, by name, phone or address.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function people(User $user, string $term): Collection
    {
        if ($term === '') {
            return collect();
        }

        $hits = collect();

        $like = fn (Builder $q) => $q->where('name', 'like', "%{$term}%")
            ->orWhere('phone', 'like', "%{$term}%")
            ->orWhere('address', 'like', "%{$term}%");

        if ($user->hasPermission('customers.view')) {
            $hits = $hits->concat(
                Customer::where($like)->orderBy('name')->limit(self::CANDIDATES)->get()
                    ->map(fn (Customer $c) => [
                        'name' => $c->displayName(),
                        'note' => __('Customer'),
                        'phone' => $c->phone,
                        'url' => route('customers.show', $c),
                        'icon' => 'people',
                    ])
            );
        }

        if ($user->hasPermission('suppliers.view')) {
            $hits = $hits->concat(
                Supplier::where($like)->orderBy('name')->limit(self::CANDIDATES)->get()
                    ->map(fn (Supplier $s) => [
                        'name' => $s->name,
                        'note' => $s->is_walk_in ? __('Sellers') : __('Supplier'),
                        'phone' => $s->phone,
                        'url' => route('suppliers.show', $s),
                        'icon' => 'truck',
                    ])
            );
        }

        return $hits->values();
    }

    /**
     * Everything with a document number on it, including the two the dropdown
     * does not carry: repairs and swaps.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function documents(User $user, string $term): Collection
    {
        if ($term === '') {
            return collect();
        }

        $kinds = [
            ['sales.view', Sale::class, __('Sale'), 'sales.show', 'receipt'],
            ['purchases.view', Purchase::class, __('Purchase'), 'purchases.show', 'journal-text'],
            ['sale_returns.view', SaleReturn::class, __('Sale return'), 'sale-returns.show', 'arrow-return-left'],
            ['purchase_returns.view', PurchaseReturn::class, __('Purchase return'), 'purchase-returns.show', 'arrow-return-right'],
            ['swaps.view', Swap::class, __('Swap'), 'swaps.show', 'arrow-left-right'],
            ['repairs.view', Repair::class, __('Repair'), 'repairs.show', 'tools'],
            ['payments.view', Payment::class, __('Payment'), 'payments.show', 'cash-coin'],
            ['expenses.view', Expense::class, __('Expense'), 'expenses.show', 'cash-stack'],
            ['stock_adjustments.view', StockAdjustment::class, __('Adjustment'), 'stock-adjustments.show', 'sliders'],
            ['stock_rooms.view', StockTransfer::class, __('Stock move'), 'stock-transfers.show', 'box-arrow-right'],
        ];

        $hits = collect();

        foreach ($kinds as [$permission, $class, $label, $route, $icon]) {
            if (! $user->hasPermission($permission)) {
                continue;
            }

            $hits = $hits->concat(
                /*
                 * Grouped as a habit rather than as a fix: Eloquent wraps the
                 * wheres it already has before a scope adds its own, so the
                 * soft-delete condition holds either way — checked by printing
                 * both queries, not assumed. It stays a closure because the
                 * next condition added here might not be a scope, and an `OR`
                 * loose in a query is how a discontinued product once got
                 * offered to the till.
                 */
                $class::where(fn (Builder $q) => $q
                    ->whereIn('document_no', $this->numbers($term))
                    ->orWhere('document_no', 'like', "%{$term}%"))
                    ->orderByDesc('id')
                    ->limit(self::RECENT)
                    ->get()
                    ->map(fn (Model $d) => [
                        'number' => $d->document_no,
                        'note' => $label,
                        'url' => route($route, $d),
                        'icon' => $icon,
                    ])
            );
        }

        return $hits->values();
    }

    /**
     * The document number a half-typed one meant.
     *
     * ⚠️ Numbers are zero-padded to five on the paper and nobody types the
     * zeros. `INV-5` is a `LIKE` that matches nothing at all against
     * `INV-00005`, which reads as "the system has lost my invoice" rather than
     * "type it the long way".
     *
     * @return list<string>
     */
    private function numbers(string $term): array
    {
        if (! preg_match('/^([A-Za-z]{3})-?(\d{1,5})$/', $term, $parts)) {
            return [];
        }

        return [strtoupper($parts[1]).'-'.str_pad($parts[2], 5, '0', STR_PAD_LEFT)];
    }

    /**
     * Everything the shop knows about one product.
     *
     * @return array<string, mixed>
     */
    private function dossier(User $user, Product $product): array
    {
        $maySell = $user->hasPermission('sales.view');
        $mayBuy = $user->hasPermission('purchases.view');

        return [
            /*
             * ⚠️ Read through TradeProfit rather than summed here. It is the
             * one piece of arithmetic the shop is judged by — revenue off the
             * sale lines, cost off the movements those lines consumed, both
             * sides of a return and the cost of a swap — and a second
             * expression of it on this page would be free to drift from the
             * report's.
             */
            'sold' => $maySell
                ? TradeProfit::between(Product::whereKey($product->id), self::beginning(), now()->addDay())
                : null,

            'bought' => $mayBuy ? $this->bought($product) : null,
            'bestSupplier' => $mayBuy ? $this->bestSupplier($product) : null,
            'bestCustomer' => $maySell ? $this->bestCustomer($product) : null,

            'soldOn' => $maySell
                ? SaleItem::with('sale.customer')
                    ->where('product_id', $product->id)
                    ->orderByDesc('id')->limit(self::RECENT)->get()
                : collect(),

            'boughtOn' => $mayBuy
                ? PurchaseItem::with('purchase.supplier')
                    ->where('product_id', $product->id)
                    ->orderByDesc('id')->limit(self::RECENT)->get()
                : collect(),
        ];
    }

    /**
     * Far enough back to mean "all of it".
     *
     * ⚠️ Not the product's own `created_at`: a sale can be dated before the row
     * that records it — a shop catching up on a week of paper does exactly that
     * — and a window that started at the row would quietly drop those lines
     * from the only figures on this page.
     */
    private static function beginning(): Carbon
    {
        return Carbon::create(1970, 1, 1)->startOfDay();
    }

    /** What the shop has put into this product, net of what went back. */
    private function bought(Product $product): array
    {
        $lines = PurchaseItem::where('product_id', $product->id);

        return [
            'units' => (int) $lines->clone()->sum(DB::raw('quantity - quantity_returned')),
            'spend' => (int) $lines->clone()->sum(DB::raw('(quantity - quantity_returned) * unit_price')),
        ];
    }

    /**
     * Who the shop buys this from most — by units, not by how often.
     *
     * ⚠️ Units rather than money: the supplier worth knowing is the one who
     * keeps the shelf full, and a single expensive order would otherwise beat
     * a year of steady ones.
     */
    private function bestSupplier(Product $product): ?object
    {
        return PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchase_items.product_id', $product->id)
            ->whereNull('purchases.deleted_at')
            ->groupBy('suppliers.id', 'suppliers.name')
            ->selectRaw('suppliers.id as id, suppliers.name as name')
            ->selectRaw('SUM(purchase_items.quantity - purchase_items.quantity_returned) as units')
            ->selectRaw('SUM((purchase_items.quantity - purchase_items.quantity_returned) * purchase_items.unit_price) as spend')
            ->selectRaw('MAX(purchases.purchase_date) as last_on')
            ->orderByDesc('units')
            ->first();
    }

    /** And who buys it most. */
    private function bestCustomer(Product $product): ?object
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sale_items.product_id', $product->id)
            ->whereNull('sales.deleted_at')
            ->groupBy('customers.id', 'customers.name')
            ->selectRaw('customers.id as id, customers.name as name')
            ->selectRaw('SUM(sale_items.quantity - sale_items.quantity_returned) as units')
            ->selectRaw('SUM((sale_items.quantity - sale_items.quantity_returned) * sale_items.unit_price) as takings')
            ->selectRaw('MAX(sales.sale_date) as last_on')
            ->orderByDesc('units')
            ->first();
    }
}
