<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Services\PurchaseReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseReturnService $returns) {}

    public function index(Request $request): View
    {
        $returns = PurchaseReturn::with('purchase', 'supplier', 'user')
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->when($request->filled('search'), fn ($q) => $q->where('document_no', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('return_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('return_date', '<=', $request->date('to')))
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        return view('purchase-returns.index', ['returns' => $returns]);
    }

    public function create(Purchase $purchase): View
    {
        $purchase->load('supplier', 'items.product', 'items.batch');

        return view('purchase-returns.create', [
            'purchase' => $purchase,

            // Section 7: the calculated discount share is shown beside the credit
            // as a hint and a one-click way to apply it. It is NOT the default —
            // purchase_return_items.discount_share defaults to 0.
            //
            // Per UNIT here; the form multiplies by the quantity returned, because
            // the stored discount_share is a whole-line figure.
            'discountShares' => $this->discountShares($purchase),

            // Section 7: purchase returns ARE limited by the batch — you can't
            // send back goods you no longer hold.
            'batchStock' => $purchase->items->mapWithKeys(fn ($item) => [
                $item->id => (int) ($item->batch?->quantity_remaining ?? 0),
            ]),
        ]);
    }

    public function store(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'lines' => ['required', 'array'],
            'lines.*.purchase_item_id' => ['required', 'exists:purchase_items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
            // Section 7: "Type any amount — whatever they gave." Negotiated
            // credits are normal, so the unit price is editable per return.
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'lines.*.discount_share' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $return = $this->returns->create(
                purchase: $purchase,
                lines: $data['lines'],
                user: $request->user(),
                returnDate: Carbon::parse($data['return_date']),
                reason: $data['reason'] ?? null,
                paymentMethod: $data['payment_method'],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('purchase-returns.show', $return)
            ->with('success', __('Return saved'));
    }

    public function show(PurchaseReturn $purchaseReturn): View
    {
        return view('purchase-returns.show', [
            'return' => $purchaseReturn->load('purchase', 'supplier', 'user', 'items.product'),
            'payments' => $purchaseReturn->payments()->orderBy('paid_at')->get(),
        ]);
    }

    /**
     * Section 7: the proportional share of the invoice discount that this line
     * carries. Shown as a hint only — Soran decides per return whether to apply it.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function discountShares(Purchase $purchase)
    {
        return $purchase->items->mapWithKeys(function ($item) use ($purchase) {
            if ($purchase->total_amount === 0 || $purchase->discount_amount === 0) {
                return [$item->id => 0];
            }

            // Per UNIT, because the return screen credits per unit.
            $share = (int) round(
                $item->unit_price * $purchase->discount_amount / $purchase->total_amount
            );

            return [$item->id => $share];
        });
    }
}
