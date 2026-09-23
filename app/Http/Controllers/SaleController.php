<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\HeldCart;
use App\Models\Sale;
use App\Services\BulkDeleteService;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function __construct(private SaleService $sales) {}

    public function index(Request $request): View
    {
        $sales = Sale::with('customer', 'user')
            // An archived period stays in the database and out of this list,
            // unless the reader asks for it.
            ->visible($request->boolean('archived'))
            // Section 9b: sort the newest first on every transactional list.
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('sale_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('sale_date', '<=', $request->date('to')))
            /*
             * ⚠️ Not just the document number — Soran, 2026-09-23: *"shoud i
             * found same inv??"*.
             *
             * A customer walks in with a faulty power bank and no paper. Until
             * this, the only way to find the sale was to know its number, so
             * the question a shop actually asks — WHO BOUGHT ONE OF THESE —
             * had no answer but scrolling. The product name, the SKU and the
             * customer now match too.
             */
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('document_no', 'like', '%'.$request->input('search').'%')
                ->orWhereHas('customer', fn ($c) => $c
                    ->where('name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('phone', 'like', '%'.$request->input('search').'%'))
                ->orWhereHas('items.product', fn ($p) => $p
                    ->where('name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('sku', 'like', '%'.$request->input('search').'%')
                    ->orWhere('barcode', 'like', '%'.$request->input('search').'%'))))
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        // Section 8c: the toggle only appears when something is hidden.
        $archivedCount = (int) Sale::archivedOnly()->count();

        return view('sales.index', [
            // Section 2b — the currency this reader wants these figures in.
            'lens' => $request->user()->lens(),
            'archivedCount' => $archivedCount,
            'sales' => $sales,
            'customers' => Customer::orderByDesc('is_system')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        // Picking a cart back up. Its lines are rebuilt against the shelf as it
        // stands now, not as it stood when the cart was put down.
        $held = $request->filled('held')
            ? HeldCart::ofType(HeldCart::TYPE_SALE)->find($request->integer('held'))
            : null;

        return view('sales.create', [
            // Section 4: the Cash Customer is the default for walk-in buyers.
            'customers' => Customer::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'cashCustomer' => Customer::cashCustomer(),
            'held' => $held,
            'heldCarts' => HeldCart::ofType(HeldCart::TYPE_SALE)->with('user')->latest()->get(),

            // What this receipt is written in, and at what rate.
            ...$this->currencyChoices(),
            // A submit that came back, before a cart that was put down. The
            // lines are already in old input — losing them means re-scanning
            // the whole basket to correct one field. See linesFor().
            'cartLines' => is_array($lines = old('lines'))
                ? HeldCartController::linesFor($lines, fn ($p) => $this->sales->nextBatchCost($p))
                : ($held ? HeldCartController::rebuild($held, fn ($p) => $this->sales->nextBatchCost($p)) : null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'sale_date' => ['required', 'date'],
            'amount_paid' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            // Section 2: IQD is whole numbers only.
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],

            /*
             * ⚠️ The receipt's own currency — Soran, 2026-09-19. §2b's decision
             * 3b said a sale is always in dinars; he sells phones priced in
             * dollars, so it is not.
             *
             * Only base-currency integers are stored. These three record what
             * was TYPED, exactly as the purchase cart has since §6b, so the
             * receipt can print it and an edit can reopen the box.
             */
            'exchange_rate' => ['nullable', 'integer', 'min:1'],
            'lines.*.entered_currency' => ['nullable', 'string', Rule::in($this->currencyCodes())],
            'lines.*.entered_amount' => ['nullable', 'integer', 'min:0'],
            'document_currency' => ['nullable', 'string', Rule::in($this->currencyCodes())],
            // The cart this came from, if it was one that had been put down.
            'held_cart_id' => ['nullable', 'integer', 'exists:held_carts,id'],
        ]);

        try {
            $sale = $this->sales->create(
                customer: Customer::findOrFail($data['customer_id']),
                lines: $data['lines'],
                user: $request->user(),
                saleDate: Carbon::parse($data['sale_date']),
                amountPaid: (int) ($data['amount_paid'] ?? 0),
                paymentMethod: $data['payment_method'],
                exchangeRate: $data['exchange_rate'] ?? null,
            );
        } catch (InsufficientStockException $e) {
            // Section 10b T8: nothing is written and no document number is
            // consumed, because the counter increments inside the same
            // transaction that just rolled back.
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // The held cart has become a real sale, so it stops being a note to
        // self. Spent here rather than when it was picked up: a cart resumed
        // and then walked away from must still be waiting tomorrow.
        if (! empty($data['held_cart_id'])) {
            HeldCart::whereKey($data['held_cart_id'])->delete();
        }

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', __('Sale saved'));
    }

    /**
     * Section 7: "Make return the obvious action on the sale page; keep edit
     * tucked away." Using edit when goods came back erases information you would
     * want later, like which customers return often.
     */
    public function edit(Sale $sale): View|RedirectResponse
    {
        $lock = $sale->canBeModified(auth()->user());

        if (! $lock['allowed']) {
            return redirect()->route('sales.show', $sale)->with('error', $lock['reason']);
        }

        $sale->load('items.product');

        return view('sales.edit', [
            'sale' => $sale,
            'cartLines' => $this->cartLines($sale),
            'customers' => Customer::where('is_active', true)
                ->orderByDesc('is_system')->orderBy('name')->get(),
            'cashCustomer' => Customer::cashCustomer(),

            // ⚠️ Read off THIS receipt, not the shop's setting: reopening a
            // dollar sale must show dollars whatever the till opens in now, or
            // its lines come back read as dinars and the money changes on a
            // screen nobody typed in.
            ...$this->currencyChoices($sale),
        ]);
    }

    /**
     * The cart screen's line shape, filled in from a saved sale.
     *
     * Stock is shown as what the edit may actually use: the update reverses
     * this sale before re-running FIFO, so the units already on the sale are
     * available to it again.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cartLines(Sale $sale): array
    {
        $onSale = $sale->items->groupBy('product_id')
            ->map(fn ($lines) => (int) $lines->sum('quantity'));

        return $sale->items->map(function ($item) use ($onSale) {
            $cost = $this->sales->nextBatchCost($item->product);

            return [
                'id' => $item->product_id,
                'name' => $item->product->name,
                'sku' => $item->product->sku,
                'unit' => $item->product->unit,
                'quantity' => $item->quantity,
                'price' => $item->unit_price,
                'stock' => $item->product->quantity + $onSale[$item->product_id],
                'cost' => $cost,
                'belowCost' => $cost !== null && $item->unit_price < $cost,
            ];
        })->values()->all();
    }

    public function update(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'sale_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $this->sales->update(
                sale: $sale,
                customer: Customer::findOrFail($data['customer_id']),
                lines: $data['lines'],
                user: $request->user(),
                saleDate: Carbon::parse($data['sale_date']),
                exchangeRate: $data['exchange_rate'] ?? null,
            );
        } catch (InsufficientStockException|\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)->with('success', __('Sale saved'));
    }

    public function destroy(Request $request, Sale $sale): RedirectResponse
    {
        try {
            $this->sales->delete($sale, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->to(after_delete(route('sales.show', $sale), route('sales.index')))
            ->with('success', __('Sale deleted and its stock put back'));
    }

    public function show(Request $request, Sale $sale): View
    {
        return view('sales.show', [
            // Section 2b — the currency this reader wants these figures in.
            'lens' => $request->user()->lens(),
            'sale' => $sale->load('customer', 'user', 'items.product', 'returns'),
            'payments' => $sale->payments()->orderBy('paid_at')->get(),
            'lockState' => $sale->canBeModified(auth()->user()),
        ]);
    }

    /**
     * Section 8b: a loop of the normal single-delete logic. Locked rows are
     * skipped and reported rather than failing the whole batch.
     */
    public function bulkDestroy(Request $request, BulkDeleteService $bulk): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:sales,id'],
        ]);

        $result = $bulk->run(
            models: Sale::whereIn('id', $data['ids'])->orderByDesc('id')->get(),
            delete: fn (Sale $sale) => $this->sales->delete($sale, $request->user()),
            guard: fn (Sale $sale) => $sale->canBeModified($request->user()),
        );

        return back()->with(
            $result['deleted'] > 0 ? 'success' : 'error',
            $bulk->summarise($result),
        );
    }

    /**
     * Every code a sale line may name — the shop's active currencies, base
     * included.
     *
     * @return list<string>
     */
    private function currencyCodes(): array
    {
        return collect(Currency::cached())
            ->filter(fn (Currency $c) => $c->is_active)
            ->keys()
            ->all();
    }

    /**
     * What the till opens in, and what to fill the rate box with.
     *
     * ⚠️ **The same setting the purchase screen reads** — Soran, 2026-09-19:
     * *"if currency on usd change sale page to usd"*. One answer for the whole
     * shop rather than two that can disagree, and the combo on the screen still
     * overrules it for the receipt in hand.
     *
     * @return array<string, mixed>
     */
    private function currencyChoices(?Sale $sale = null): array
    {
        $base = Money::base();

        $foreign = collect(Currency::cached())
            ->filter(fn (Currency $c) => $c->is_active && $c->code !== $base->code)
            ->values();

        $was = $sale !== null
            ? $sale->items
                ->pluck('entered_currency')
                ->first(fn (?string $code) => $code !== null && $code !== $base->code)
            : (setting('purchase_currency') ?: null);

        // A receipt written in a currency since switched off still opens on it,
        // or its lines would come back on a code the select cannot show.
        if ($sale !== null && $was !== null && ! $foreign->contains('code', $was)) {
            $kept = Currency::cached()[$was] ?? null;

            if ($kept !== null) {
                $foreign = $foreign->push($kept)->values();
            }
        }

        $wanted = $was ?: $base->code;

        $chosen = $wanted === $base->code ? null : $foreign->firstWhere('code', $wanted);

        return [
            'base' => $base,
            'foreignCurrencies' => $foreign,
            'documentCurrency' => $chosen?->code ?? $base->code,
            'documentRate' => (int) ($sale?->exchange_rate
                ?: ($chosen ? (int) round($chosen->rate / Money::RATE_SCALE) : 0)),
            'currencyMeta' => collect(Currency::cached())
                ->filter(fn (Currency $c) => $c->is_active)
                ->map(fn (Currency $c) => [
                    'code' => $c->code,
                    'mark' => $c->mark(),
                    'decimals' => $c->decimals,
                    'minorPerMajor' => $c->minorPerMajor(),
                    'rate' => (int) round($c->rate / Money::RATE_SCALE),
                ])
                ->all(),
        ];
    }
}
