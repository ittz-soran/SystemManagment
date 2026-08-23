<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One box for the whole shop.
 *
 * A shopkeeper holding a printed invoice does not want to work out which screen
 * it belongs to; they want to type INV-00005. The same box finds a customer by
 * name, a product by barcode, and the screen called "Stock adjustments" for
 * somebody who cannot remember where it lives.
 *
 * Every group is behind the permission of the screen it would send the reader
 * to. That is not decoration: a search box that lists the expenses it will not
 * let you open has told you what the shop spends its money on, which is exactly
 * what withholding the screen was for. A reader without expenses.view gets no
 * expenses — not a locked row, no row.
 */
class SearchController extends Controller
{
    /** Enough to be useful in a dropdown, few enough to stay readable. */
    private const PER_GROUP = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();
        $user = $request->user();

        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }

        $groups = array_filter([
            $this->pages($user, $term),
            $this->products($user, $term),
            $this->people($user, $term),
            ...$this->documents($user, $term),
        ]);

        return response()->json(['groups' => array_values($groups)]);
    }

    /**
     * Screens, by name. The reader is told where a screen is rather than made to
     * remember, and only screens they may open are offered.
     *
     * @return array<string, mixed>|null
     */
    private function pages(User $user, string $term): ?array
    {
        $hits = collect(Navigation::pagesFor($user))
            ->filter(fn (array $page) => mb_stripos($page['label'], $term) !== false)
            ->take(self::PER_GROUP)
            ->map(fn (array $page) => [
                'label' => $page['label'],
                'note' => $page['group'],
                'url' => route($page['route']),
                'icon' => $page['icon'],
            ])
            ->values();

        return $hits->isEmpty() ? null : ['label' => __('Screens'), 'items' => $hits];
    }

    /**
     * Products, second-hand items and services together, each saying which it
     * is — they are three different things to buy and one thing to look up.
     *
     * @return array<string, mixed>|null
     */
    private function products(User $user, string $term): ?array
    {
        if (! $user->hasPermission('products.view')) {
            return null;
        }

        $hits = Product::query()
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%"))
            // An exact code first: someone scanning a barcode wants that one row.
            ->orderByRaw('CASE WHEN barcode = ? OR sku = ? THEN 0 ELSE 1 END', [$term, $term])
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Product $p) => [
                'label' => $p->name,
                'note' => trim($p->sku.' · '.match ($p->kind) {
                    Product::KIND_USED => __('Second-hand'),
                    Product::KIND_SERVICE => __('Service'),
                    default => trans_choice('{0}out of stock|{1}:count in stock|[2,*]:count in stock',
                        $p->quantity, ['count' => number_format($p->quantity)]),
                }),
                'url' => route('products.show', $p),
                'icon' => 'box-seam',
            ]);

        return $hits->isEmpty() ? null : ['label' => __('Products'), 'items' => $hits];
    }

    /**
     * Customers and suppliers in one group, since a name is a name — but each
     * half only for a reader allowed that half.
     *
     * @return array<string, mixed>|null
     */
    private function people(User $user, string $term): ?array
    {
        $hits = collect();

        if ($user->hasPermission('customers.view')) {
            $hits = $hits->concat(
                Customer::where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orderBy('name')->limit(self::PER_GROUP)->get()
                    ->map(fn (Customer $c) => [
                        'label' => $c->displayName(),
                        'note' => trim(__('Customer').($c->phone ? ' · '.$c->phone : '')),
                        'url' => route('customers.show', $c),
                        'icon' => 'people',
                    ])
            );
        }

        if ($user->hasPermission('suppliers.view')) {
            $hits = $hits->concat(
                Supplier::where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orderBy('name')->limit(self::PER_GROUP)->get()
                    ->map(fn (Supplier $s) => [
                        'label' => $s->name,
                        'note' => trim(($s->is_walk_in ? __('Sellers') : __('Supplier'))
                            .($s->phone ? ' · '.$s->phone : '')),
                        'url' => route('suppliers.show', $s),
                        'icon' => 'truck',
                    ])
            );
        }

        $hits = $hits->take(self::PER_GROUP * 2)->values();

        return $hits->isEmpty() ? null : ['label' => __('People'), 'items' => $hits];
    }

    /**
     * Everything with a document number on it.
     *
     * The number is what is printed on the paper in the reader's hand, so it is
     * what the box matches — and each kind carries the permission of its own
     * screen, because a payment is not an invoice and the shop may well let
     * somebody see one and not the other.
     *
     * @return list<array<string, mixed>|null>
     */
    private function documents(User $user, string $term): array
    {
        $kinds = [
            ['sales.view', Sale::class, __('Sales'), 'sales.show', 'receipt', ['customer']],
            ['purchases.view', Purchase::class, __('Purchases'), 'purchases.show', 'journal-text', ['supplier']],
            ['sale_returns.view', SaleReturn::class, __('Sale returns'), 'sale-returns.show', 'arrow-return-left', ['customer']],
            ['purchase_returns.view', PurchaseReturn::class, __('Purchase returns'), 'purchase-returns.show', 'arrow-return-right', ['supplier']],
            ['payments.view', Payment::class, __('Payments'), 'payments.show', 'cash-coin', []],
            ['expenses.view', Expense::class, __('Expenses'), 'expenses.show', 'cash-stack', ['category']],
            ['stock_adjustments.view', StockAdjustment::class, __('Stock adjustments'), 'stock-adjustments.show', 'sliders', ['product']],
        ];

        $groups = [];

        foreach ($kinds as [$permission, $class, $label, $route, $icon, $with]) {
            if (! $user->hasPermission($permission)) {
                continue;
            }

            $hits = $class::query()
                ->with($with)
                ->where('document_no', 'like', "%{$term}%")
                ->orderByDesc('id')
                ->limit(self::PER_GROUP)
                ->get()
                ->map(fn (Model $document) => [
                    'label' => $document->document_no,
                    'note' => $this->describe($document),
                    'url' => route($route, $document),
                    'icon' => $icon,
                ]);

            if ($hits->isNotEmpty()) {
                $groups[] = ['label' => $label, 'items' => $hits];
            }
        }

        return $groups;
    }

    /** Enough beside the number to tell two of them apart. */
    private function describe(Model $document): string
    {
        return match (true) {
            $document instanceof Sale, $document instanceof SaleReturn
                => $document->customer?->displayName() ?? '',
            $document instanceof Purchase, $document instanceof PurchaseReturn
                => $document->supplier?->name ?? '',
            $document instanceof Expense
                => trim($document->title.' · '.($document->category?->name ?? '')),
            $document instanceof StockAdjustment
                => $document->product?->name ?? '',
            $document instanceof Payment
                => money($document->amount, false),
            default => '',
        };
    }
}
