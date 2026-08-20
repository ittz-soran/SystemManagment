<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Supplier;
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

        return view('purchases.index', [
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

    public function show(Purchase $purchase): View
    {
        return view('purchases.show', [
            'purchase' => $purchase->load('supplier', 'user', 'items.product', 'returns'),
            'payments' => $purchase->payments()->orderBy('paid_at')->get(),
            'lockState' => $purchase->canBeModified(auth()->user()),
            'deleteState' => $purchase->canBeDeleted(auth()->user()),
        ]);
    }
}
