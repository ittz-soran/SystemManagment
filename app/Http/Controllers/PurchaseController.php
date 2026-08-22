<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\BulkDeleteService;
use App\Services\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function __construct(private PurchaseService $purchases) {}

    public function index(Request $request): View
    {
        $purchases = Purchase::with('supplier', 'user')
            // An archived period stays in the database and out of this list,
            // unless the reader asks for it.
            ->visible($request->boolean('archived'))
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->input('supplier_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('purchase_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('purchase_date', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('document_no', 'like', '%'.$request->input('search').'%')
                ->orWhere('supplier_invoice_no', 'like', '%'.$request->input('search').'%')))
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        // Section 8c: the toggle only appears when something is hidden.
        $archivedCount = (int) Purchase::archivedOnly()->count();

        return view('purchases.index', [
            'archivedCount' => $archivedCount,
            'purchases' => $purchases,
            'suppliers' => Supplier::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('purchases.create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            // Section 6b: pre-filled from settings, editable per purchase,
            // because the rate you actually paid at is the one that matters.
            'usdRate' => (int) setting('usd_rate', 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_date' => ['required', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],
            // Section 6: SIGNED, because a supplier may round UP.
            'discount_amount' => ['nullable', 'integer'],
            'amount_paid' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'exchange_rate' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.entered_currency' => ['nullable', 'in:IQD,USD'],
            'lines.*.entered_amount' => ['nullable', 'integer', 'min:0'],
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
                paymentMethod: $data['payment_method'],
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
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
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            // Section 6b: the rate this purchase was actually entered at, so
            // re-saving it does not silently reprice the USD lines.
            'usdRate' => (int) ($purchase->exchange_rate ?: setting('usd_rate', 0)),
        ]);
    }

    /**
     * The cart screen's line shape, filled in from a saved purchase.
     *
     * entered_amount is stored in cents, which is what the form posts, so it is
     * divided back out for the dollars-and-cents box.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cartLines(Purchase $purchase): array
    {
        return $purchase->items->map(fn ($item) => [
            'id' => $item->product_id,
            'name' => $item->product->name,
            'sku' => $item->product->sku,
            'quantity' => $item->quantity,
            'currency' => $item->entered_currency,
            'enteredAmount' => $item->entered_currency === 'USD'
                ? round($item->entered_amount / 100, 2)
                : 0,
            'price' => $item->unit_price,
        ])->values()->all();
    }

    public function update(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_date' => ['required', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],
            'discount_amount' => ['nullable', 'integer'],
            'exchange_rate' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.entered_currency' => ['nullable', 'in:IQD,USD'],
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

        return redirect()->route('purchases.index')
            ->with('success', __('Purchase deleted and its stock removed'));
    }

    public function show(Purchase $purchase): View
    {
        return view('purchases.show', [
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
