<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsGoodsComingBack;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Services\PurchaseReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

class PurchaseReturnController extends Controller
{
    use ListsGoodsComingBack;

    public function __construct(private PurchaseReturnService $returns) {}

    public function index(Request $request): View
    {
        $filtered = $this->cameBack(PurchaseReturn::query(), $request, 'return_date');

        $returns = (clone $filtered)->with('purchase', 'supplier', 'user')
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        // Section 8c: the toggle only appears when something is hidden.
        $archivedCount = (int) PurchaseReturn::archivedOnly()->count();

        return view('purchase-returns.index', [
            'lens' => $request->user()->lens(),
            'archivedCount' => $archivedCount,
            'returns' => $returns,
            'isFiltered' => $this->isFiltered($request),
            'stats' => $this->figures($filtered, $request),
        ]);
    }

    /**
     * The same four questions as the sale-return list, in the supplier's
     * vocabulary: they credit rather than refund, and the units leave the
     * shelf rather than come back to it.
     *
     * @param  Builder<PurchaseReturn>  $filtered
     * @return array<int, array{label: string, value: string, note: string}>
     */
    private function figures($filtered, Request $request): array
    {
        $lens = $request->user()->lens();

        $count = (int) (clone $filtered)->count();
        $credited = (int) (clone $filtered)->sum('total_amount');

        $units = (int) PurchaseReturnItem::whereIn('purchase_return_id', (clone $filtered)->select('id'))
            ->sum('quantity');

        // What actually came back as money. The rest came off what the shop
        // still owed that supplier, which never moved.
        $cash = $this->settledInCash($filtered, new PurchaseReturn);

        return [
            [
                'label' => __('Returns'),
                'value' => number_format($count),
                'note' => __('documents on this list'),
            ],
            [
                'label' => __('Units sent back'),
                'value' => number_format($units),
                'note' => __('and off the shelf'),
            ],
            [
                'label' => __('Credited'),
                'value' => money($credited, in: $lens),
                'note' => __('what the suppliers owed back'),
            ],
            [
                'label' => __('Cash back'),
                'value' => money($cash, in: $lens),
                'note' => __(':amount came off what you owed instead', [
                    'amount' => money(max(0, $credited - $cash), in: $lens),
                ]),
            ],
        ];
    }

    public function create(Request $request, Purchase $purchase): View
    {
        $purchase->load('supplier', 'items.product', 'items.batch');

        return view('purchase-returns.create', [
            // Section 2b — the credit boxes take this currency, and the
            // figures beside them are read in it.
            'lens' => $request->user()->lens(),
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

    public function show(Request $request, PurchaseReturn $purchaseReturn): View
    {
        return view('purchase-returns.show', [
            'lens' => $request->user()->lens(),
            'return' => $purchaseReturn->load('purchase', 'supplier', 'user', 'items.product'),
            'payments' => $purchaseReturn->payments()->orderBy('paid_at')->get(),
            // Section 9b: the button is always shown — disabled with the reason
            // as its tooltip when it cannot be used.
            'deleteState' => $purchaseReturn->canBeDeleted(auth()->user()),
        ]);
    }

    /**
     * Section 5: undoing a return to a supplier puts the goods back in the batch
     * they left, takes the credit off, and sends the cash back out.
     */
    public function destroy(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        try {
            $this->returns->delete($purchaseReturn, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('purchases.show', $purchaseReturn->purchase_id)
            ->with('success', __('Return deleted and the goods put back'));
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
