<?php

namespace App\Http\Controllers;

use App\Models\AccountTransaction;
use App\Models\Currency;
use App\Models\HeldCart;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockRoom;
use App\Models\Supplier;
use App\Services\BulkDeleteService;
use App\Services\PurchaseService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function __construct(private PurchaseService $purchases) {}

    public function index(Request $request): View
    {
        $filtered = Purchase::query()
            // An archived period stays in the database and out of this list,
            // unless the reader asks for it.
            ->visible($request->boolean('archived'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->input('supplier_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('purchase_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('purchase_date', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('document_no', 'like', '%'.$request->input('search').'%')
                ->orWhere('supplier_invoice_no', 'like', '%'.$request->input('search').'%')));

        $purchases = (clone $filtered)->with('supplier', 'user')
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        // Section 8c: the toggle only appears when something is hidden.
        $archivedCount = (int) Purchase::archivedOnly()->count();

        return view('purchases.index', [
            // Section 2b — the currency this reader wants these figures in.
            'lens' => $request->user()->lens(),
            'archivedCount' => $archivedCount,
            'purchases' => $purchases,
            'suppliers' => Supplier::companies()->orderBy('name')->get(),
            'isFiltered' => $this->isFiltered($request),
            'stats' => $this->figures($filtered, $request),
        ]);
    }

    /**
     * The four figures over the list, in the supplier's vocabulary — the same
     * four the sales list answers about customers.
     *
     * ⚠️ **`grand_total`, not `total_amount`.** The discount comes off the
     * invoice, and what the shop owes is what it agreed to pay — the list's own
     * "Grand total" column reads the same field, so a tile on `total_amount`
     * would sum to more than the rows under it.
     *
     * @param  Builder<Purchase>  $filtered
     * @return array<int, array{label: string, value: string, note: string}>
     */
    private function figures($filtered, Request $request): array
    {
        $lens = $request->user()->lens();
        $ids = (clone $filtered)->select('id');

        $count = (int) (clone $filtered)->count();
        $total = (int) (clone $filtered)->sum('grand_total');

        /*
         * ⚠️ **There is no `amount_paid` column** — a first version of this
         * summed one and every tile read zero. What has been paid is the
         * payments netted, money the other way being money handed back.
         *
         * ⚠️ And `payable_type` holds the MORPH ALIAS, not the class name.
         * Written with `::class` the query matches nothing and the tile reads a
         * confident zero — the same trap the sale-return list fell into a day
         * ago. `getMorphClass()` is the alias, whatever the map says today.
         */
        $paid = (int) Payment::where('payable_type', (new Purchase)->getMorphClass())
            ->whereIn('payable_id', $ids)
            ->where('direction', Payment::DIRECTION_OUT)
            ->sum('amount')
            - (int) Payment::where('payable_type', (new Purchase)->getMorphClass())
                ->whereIn('payable_id', $ids)
                ->where('direction', Payment::DIRECTION_IN)
                ->sum('amount');

        /*
         * ⚠️ **A return credits the document as surely as a payment settles
         * it**, and `Purchase::amountDue()` subtracts both. Left out here, this
         * strip would disagree with the Due column in the rows underneath it.
         */
        $credited = -(int) AccountTransaction::query()
            ->where('reference_type', 'purchase_return')
            ->whereIn('reference_id', PurchaseReturn::whereIn('purchase_id', $ids)->select('id'))
            ->sum('amount');

        $due = max(0, $total - $paid - $credited);

        // How many are carrying it, which is the difference between one
        // awkward account and a habit.
        $owing = (clone $filtered)->get()->filter(fn ($row) => $row->amountDue() > 0)->count();

        return [
            [
                'label' => __('Purchases'),
                'value' => number_format($count),
                'note' => __('documents on this list'),
            ],
            [
                'label' => __('Bought'),
                'value' => money($total, in: $lens),
                'note' => __('what these purchases came to'),
            ],
            [
                'label' => __('Paid'),
                'value' => money($paid, in: $lens),
                'note' => $credited > 0
                    ? __(':amount more came off as returns', ['amount' => money($credited, in: $lens)])
                    : __('handed over so far'),
            ],
            [
                'label' => __('Still owed'),
                'value' => money($due, in: $lens),
                'note' => trans_choice(
                    '{0}every one of them is settled|{1}on one purchase|[2,*]across :count purchases',
                    $owing, ['count' => number_format($owing)],
                ),
            ],
        ];
    }

    /** Whether the reader narrowed the list. */
    private function isFiltered(Request $request): bool
    {
        return $request->filled('search')
            || $request->filled('from')
            || $request->filled('to')
            || $request->filled('status')
            || $request->filled('supplier_id')
            || $request->boolean('archived');
    }

    public function create(Request $request): View
    {
        // Picking a cart back up. Its lines are rebuilt against the shelf as it
        // stands now, not as it stood when the cart was put down.
        $held = $request->filled('held')
            ? HeldCart::ofType(HeldCart::TYPE_PURCHASE)->find($request->integer('held'))
            : null;

        return view('purchases.create', [
            'held' => $held,
            'heldCarts' => HeldCart::ofType(HeldCart::TYPE_PURCHASE)->with('user')->latest()->get(),
            // A submit that came back, before a cart that was put down. The
            // lines are already in old input — losing them means re-scanning
            // the whole basket to correct one field. See linesFor().
            'cartLines' => is_array($lines = old('lines'))
                ? HeldCartController::linesFor($lines, fn ($p) => null)
                : ($held ? HeldCartController::rebuild($held, fn ($p) => null) : null),
            'suppliers' => Supplier::companies()->where('is_active', true)->orderBy('name')->get(),

            /*
             * Where a delivery may be booked. Active rooms only — a closed room
             * is one the shop has stopped using, and offering it would let
             * somebody book stock into a place nothing can be moved into.
             */
            'rooms' => StockRoom::where('is_active', true)
                ->orderByDesc('is_main')->orderBy('sort_order')->orderBy('name')->get(),
            // Section 6b: pre-filled from settings, editable per purchase,
            // because the rate you actually paid at is the one that matters.
            ...$this->currencyChoices(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_date' => ['required', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],

            /*
             * Where the delivery went — Soran, 2026-09-18: *"add purchase
             * directly to other rooms"*. Nullable, and null means the shop
             * floor: a shop with one room never sees this control and every
             * purchase it has ever written means the same thing.
             */
            'room_id' => ['nullable', Rule::exists('stock_rooms', 'id')->whereNull('deleted_at')],
            // Section 6: SIGNED, because a supplier may round UP.
            'discount_amount' => ['nullable', 'integer'],
            'amount_paid' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'exchange_rate' => ['nullable', 'integer', 'min:1'],
            /*
             * Validated, and then not used. The invoice currency is the entry
             * screen's own memory: it says which currency the per-line toggle
             * offers, and every line already carries its own answer. It is
             * posted so a refused save redraws the screen the way it was left.
             */
            'document_currency' => ['nullable', 'string', Rule::in($this->currencyCodes())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.entered_currency' => ['nullable', 'string', Rule::in($this->currencyCodes())],
            'lines.*.entered_amount' => ['nullable', 'integer', 'min:0'],
            // The cart this came from, if it was one that had been put down.
            'held_cart_id' => ['nullable', 'integer', 'exists:held_carts,id'],
        ]);

        try {
            $purchase = $this->purchases->create(
                supplier: Supplier::findOrFail($data['supplier_id']),
                lines: $data['lines'],
                user: $request->user(),
                purchaseDate: \Illuminate\Support\Carbon::parse($data['purchase_date']),
                discountAmount: (int) ($data['discount_amount'] ?? 0),
                amountPaid: (int) ($data['amount_paid'] ?? 0),
                supplierInvoiceNo: $data['supplier_invoice_no'] ?? null,
                exchangeRate: $data['exchange_rate'] ?? null,
                roomId: $data['room_id'] ?? null,
                paymentMethod: $data['payment_method'],
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // The held cart has become a real purchase, so it stops being a note
        // to self. Spent here rather than when it was picked up: a cart resumed
        // and then walked away from must still be waiting tomorrow.
        if (! empty($data['held_cart_id'])) {
            HeldCart::whereKey($data['held_cart_id'])->delete();
        }

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('success', __('Purchase saved'));
    }

    public function edit(Purchase $purchase): View|RedirectResponse
    {
        $lock = $purchase->canBeModified(auth()->user());

        if (! $lock['allowed']) {
            return redirect()->route('purchases.show', $purchase)->with('error', $lock['reason']);
        }

        $purchase->load('items.product');

        return view('purchases.edit', [
            'purchase' => $purchase,
            'cartLines' => $this->cartLines($purchase),
            'suppliers' => Supplier::companies()->where('is_active', true)->orderBy('name')->get(),

            /*
             * Where a delivery may be booked. Active rooms only — a closed room
             * is one the shop has stopped using, and offering it would let
             * somebody book stock into a place nothing can be moved into.
             */
            'rooms' => StockRoom::where('is_active', true)
                ->orderByDesc('is_main')->orderBy('sort_order')->orderBy('name')->get(),
            // Section 6b: the rate this purchase was actually entered at, so
            // re-saving it does not silently reprice the USD lines.
            ...$this->currencyChoices($purchase),
        ]);
    }

    /**
     * Every code a line may name — the shop's active currencies, base included.
     *
     * ⚠️ Plus whatever the purchase being edited already says. Switching a
     * currency off in Settings stops NEW documents naming it; it must not make
     * an old one unsaveable, which is what happens if the code its lines
     * already carry is refused the moment somebody opens it to fix a quantity.
     *
     * @return list<string>
     */
    private function currencyCodes(?Purchase $purchase = null): array
    {
        return collect(Currency::cached())
            ->filter(fn (Currency $c) => $c->is_active)
            ->keys()
            ->merge($purchase?->items->pluck('entered_currency')->filter()->all() ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The currencies this invoice may be written in, and the rate to use.
     *
     * Section 2b generalises Section 6b's helper: the choice was hard-coded to
     * IQD or USD, and is now whichever currencies the shop keeps. What has not
     * changed is the rule underneath — only base-currency integers are stored,
     * and this is a calculator on the entry form.
     *
     * ⚠️ ONE foreign currency per invoice, not one per line. A supplier
     * invoices in one currency, `purchases.exchange_rate` is one column, and
     * printing the foreign figure beside the base one needs a single rate to
     * print. A line still chooses between the base and that currency, which is
     * what the per-line toggle has always offered.
     *
     * @return array<string, mixed>
     */
    private function currencyChoices(?Purchase $purchase = null): array
    {
        $base = Money::base();

        $foreign = collect(Currency::cached())
            ->filter(fn (Currency $c) => $c->is_active && $c->code !== $base->code)
            ->values();

        /*
         * What this invoice was written in, if it is being edited. Read off the
         * lines, because that is where it was recorded.
         *
         * ⚠️ **A NEW one opens on what the shop says, not on what it did last
         * — Soran, 2026-09-18: "purchase always in dinar or system selected
         * which currency use it".**
         *
         * It used to look up the most recent foreign line and open on that,
         * which is a guess made from history: one dollar invoice typed months
         * ago and every purchase since opens in dollars, including the ordinary
         * dinar ones. Settings → "Purchases are written in" is the shop saying
         * so outright, and blank there means its own money.
         *
         * Still only a default. The Invoice currency combo is untouched,
         * because a supplier who invoices in dollars does not care what the
         * shop usually does.
         */
        $was = $purchase !== null
            ? $purchase->items
                ->pluck('entered_currency')
                ->first(fn (?string $code) => $code !== null && $code !== $base->code)
            : (setting('purchase_currency') ?: null);

        // ⚠️ And the same for the choice on the screen: a purchase written in
        // a currency since switched off still opens on it, or its lines would
        // come back on a code the select cannot show.
        if ($purchase !== null && $was !== null && ! $foreign->contains('code', $was)) {
            $kept = Currency::cached()[$was] ?? null;

            if ($kept !== null) {
                $foreign = $foreign->push($kept)->values();
            }
        }

        /*
         * ⚠️ **`?? $foreign->first()` is why this never opened in dinars.**
         *
         * `$foreign` excludes the base, so falling back to its first entry made
         * the screen open in a foreign currency whenever the shop had one
         * switched on — whether or not a single purchase had ever been written
         * in it. A shop that buys everything in dinars and keeps USD on the
         * list for reading prices opened every purchase in dollars.
         *
         * null is now a real answer and it means the shop's own money, which is
         * what a blank setting says and what an invoice with no foreign line
         * has always meant.
         */
        $wanted = $was ?: $base->code;

        $chosen = $wanted === $base->code
            ? null
            : $foreign->firstWhere('code', $wanted);

        return [
            'base' => $base,
            'foreignCurrencies' => $foreign,

            /*
             * The rate box is on the screen from the start, which is how this
             * has always looked: Section 6b put a USD rate on every purchase
             * whether or not anything was typed in dollars. Lines still open in
             * the base currency, so nothing is converted until somebody asks.
             */
            'documentCurrency' => $chosen?->code ?? $base->code,

            /*
             * ⚠️ A WHOLE number of base units per one foreign unit, which is
             * what `purchases.exchange_rate` has always held. The currencies
             * table can carry 1,320.125 for reading; a document records what
             * was typed into this box, and widening the column would change
             * the meaning of every rate already recorded.
             */
            'documentRate' => (int) ($purchase?->exchange_rate
                ?: ($chosen ? (int) round($chosen->rate / Money::RATE_SCALE) : 0)),

            /*
             * What the cart's JavaScript needs to draw and post a line: how a
             * currency is written, how many places it takes, and how many minor
             * units make one of it. `rate` is the saved rate, in the same whole
             * base units as the box above, so switching the invoice currency
             * can fill that box in.
             */
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

    /**
     * The cart screen's line shape, filled in from a saved purchase.
     *
     * entered_amount is stored in the typed currency's minor units, which is
     * what the form posts, so it is divided back out for the box.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cartLines(Purchase $purchase): array
    {
        return $purchase->items->map(fn ($item) => [
            'id' => $item->product_id,
            'name' => $item->product->name,
            'sku' => $item->product->sku,
            'unit' => $item->product->unit,
            'quantity' => $item->quantity,
            'price' => $item->unit_price,

            /*
             * ⚠️ Null when nobody typed a foreign figure for this line. The
             * screen then draws its box by converting `price`, and correcting
             * the rate leaves that price alone — the untouched-field rule of
             * Section 2b. A line that DOES carry a typed figure follows the
             * rate instead, because that figure is what the supplier charged.
             */
            'typed' => $item->entered_amount === null ? null : $item->typedAmount(),
        ])->values()->all();
    }

    public function update(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_date' => ['required', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],

            /*
             * Where the delivery went — Soran, 2026-09-18: *"add purchase
             * directly to other rooms"*. Nullable, and null means the shop
             * floor: a shop with one room never sees this control and every
             * purchase it has ever written means the same thing.
             */
            'room_id' => ['nullable', Rule::exists('stock_rooms', 'id')->whereNull('deleted_at')],
            'discount_amount' => ['nullable', 'integer'],
            'exchange_rate' => ['nullable', 'integer', 'min:1'],
            // The entry screen's own memory — see store().
            'document_currency' => ['nullable', 'string', Rule::in($this->currencyCodes($purchase))],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.entered_currency' => ['nullable', 'string', Rule::in($this->currencyCodes($purchase))],
            'lines.*.entered_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $this->purchases->update(
                purchase: $purchase,
                supplier: Supplier::findOrFail($data['supplier_id']),
                lines: $data['lines'],
                user: $request->user(),
                purchaseDate: \Illuminate\Support\Carbon::parse($data['purchase_date']),
                discountAmount: (int) ($data['discount_amount'] ?? 0),
                supplierInvoiceNo: $data['supplier_invoice_no'] ?? null,
                exchangeRate: $data['exchange_rate'] ?? null,
                roomId: $data['room_id'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.show', $purchase)->with('success', __('Purchase saved'));
    }

    public function destroy(Request $request, Purchase $purchase): RedirectResponse
    {
        try {
            $this->purchases->delete($purchase, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->to(after_delete(route('purchases.show', $purchase), route('purchases.index')))
            ->with('success', __('Purchase deleted and its stock removed'));
    }

    public function show(Request $request, Purchase $purchase): View
    {
        return view('purchases.show', [
            // Section 2b — the currency this reader wants these figures in.
            'lens' => $request->user()->lens(),
            'purchase' => $purchase->load('supplier', 'user', 'items.product', 'returns'),
            'payments' => $purchase->payments()->orderBy('paid_at')->get(),
            'lockState' => $purchase->canBeModified(auth()->user()),
            'deleteState' => $purchase->canBeDeleted(auth()->user()),
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
            'ids.*' => ['integer', 'exists:purchases,id'],
        ]);

        $result = $bulk->run(
            models: Purchase::whereIn('id', $data['ids'])->orderByDesc('id')->get(),
            delete: fn (Purchase $purchase) => $this->purchases->delete($purchase, $request->user()),
            guard: fn (Purchase $purchase) => $purchase->canBeDeleted($request->user()),
        );

        return back()->with(
            $result['deleted'] > 0 ? 'success' : 'error',
            $bulk->summarise($result),
        );
    }
}
