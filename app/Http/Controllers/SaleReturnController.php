<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsGoodsComingBack;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\SaleReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Section 7: partial line, whole line, or whole sale — one form, one mechanism.
 *
 * Returns are never blocked by the edit lock. A return creates a new forward
 * document; it doesn't rewrite history.
 */
class SaleReturnController extends Controller
{
    use ListsGoodsComingBack;

    public function __construct(private SaleReturnService $returns) {}

    public function index(Request $request): View
    {
        $filtered = $this->cameBack(SaleReturn::query(), $request, 'return_date');

        $returns = (clone $filtered)->with('sale', 'customer', 'user')
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->paginate($request->user()->items_per_page)
            ->withQueryString();

        // Section 8c: the toggle only appears when something is hidden.
        $archivedCount = (int) SaleReturn::archivedOnly()->count();

        return view('sale-returns.index', [
            'lens' => $request->user()->lens(),
            'archivedCount' => $archivedCount,
            'returns' => $returns,
            'isFiltered' => $this->isFiltered($request),
            'stats' => $this->figures($filtered, $request),
        ]);
    }

    /**
     * The four figures over the list, in the customer's vocabulary.
     *
     * ⚠️ **Of the filtered range, not of all time**, and each is asked of the
     * same query the table is drawn from — `clone`d rather than re-built, so a
     * filter that reaches the rows cannot fail to reach the totals. A figure
     * that quietly counts something else from the list under it is the exact
     * complaint that opened this week: *"in services total show 290,000 … in
     * reports show 231,000"*.
     *
     * @param  Builder<SaleReturn>  $filtered
     * @return array<int, array{label: string, value: string, note: string}>
     */
    private function figures($filtered, Request $request): array
    {
        $lens = $request->user()->lens();

        $count = (int) (clone $filtered)->count();
        $refunded = (int) (clone $filtered)->sum('total_amount');

        $units = (int) SaleReturnItem::whereIn('sale_return_id', (clone $filtered)->select('id'))
            ->sum('quantity');

        // What actually left the till. The rest of the refund went against what
        // the customer already owed, which is money that never moved.
        $cash = $this->settledInCash($filtered, new SaleReturn);

        return [
            [
                'label' => __('Returns'),
                'value' => number_format($count),
                'note' => __('documents on this list'),
            ],
            [
                'label' => __('Units back on the shelf'),
                'value' => number_format($units),
                'note' => __('and sellable again'),
            ],
            [
                'label' => __('Refunded'),
                'value' => money($refunded, in: $lens),
                'note' => __('what the customers were given back'),
            ],
            [
                'label' => __('Cash out of the till'),
                'value' => money($cash, in: $lens),
                'note' => __(':amount came off what they owed instead', [
                    'amount' => money(max(0, $refunded - $cash), in: $lens),
                ]),
            ],
        ];
    }

    /** The return screen for one sale. */
    public function create(Request $request, Sale $sale): View
    {
        $sale->load('customer', 'items.product');

        /*
         * Where each line's units came from, so the shop decides with the
         * answer already on the page rather than after a second screen —
         * Soran, 2026-09-23. Keyed by sale item, and worked out for the whole
         * returnable quantity because that is the most the form can send.
         */
        $origins = $sale->items->mapWithKeys(fn ($item) => [
            $item->id => $this->returns->originsFor($item, $item->returnableQuantity()),
        ]);

        /*
         * One line already in mind, because the reader came from the swap page
         * — Soran, 2026-09-23: *"after select one open it on sale return and
         * marked as wanted product to return"*.
         *
         * ⚠️ Looked up among THIS sale's own lines, so a hand-typed id cannot
         * mark a line belonging to somebody else's invoice. It only fills a box
         * in; the reader still presses the button.
         */
        $preselected = $sale->items->firstWhere('id', $request->integer('line'))?->id;

        return view('sale-returns.create', [
            // Section 2b — the refund figures are read in this currency.
            'lens' => $request->user()->lens(),
            'sale' => $sale,
            'origins' => $origins,
            'preselected' => $preselected,
            // Sending it back writes a purchase return, which is its own key.
            'maySendBack' => $request->user()->hasPermission('purchase_returns.create'),
        ]);
    }

    public function store(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'in:cash,bank,transfer'],
            'lines' => ['required', 'array'],
            'lines.*.sale_item_id' => ['required', 'exists:sale_items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],

            // Which lines are going back to the supplier. Sale item ids, and
            // they must belong to this sale.
            'faulty' => ['array'],
            'faulty.*' => ['integer', Rule::exists('sale_items', 'id')->where('sale_id', $sale->id)],
        ]);

        /*
         * ⚠️ Writing a purchase return is `purchase_returns.create`, not
         * `sale_returns.create`. Somebody at the counter who may take a return
         * is not thereby somebody who may bill a supplier, and the screen does
         * not offer it to them — this is the same rule enforced again where it
         * counts, because a form can be edited.
         */
        $faulty = $request->user()->hasPermission('purchase_returns.create')
            ? ($data['faulty'] ?? [])
            : [];

        try {
            $return = $this->returns->create(
                sale: $sale,
                lines: $data['lines'],
                user: $request->user(),
                returnDate: Carbon::parse($data['return_date']),
                reason: $data['reason'] ?? null,
                paymentMethod: $data['payment_method'],
                faultyLines: $faulty,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('sale-returns.show', $return)
            ->with('success', __('Return saved'));
    }

    public function show(Request $request, SaleReturn $saleReturn): View
    {
        return view('sale-returns.show', [
            'lens' => $request->user()->lens(),
            'return' => $saleReturn->load('sale', 'customer', 'user', 'items.product'),
            'payments' => $saleReturn->payments()->orderBy('paid_at')->get(),
            // Section 8: computed live and re-checked inside the transaction.
            // The view uses it to disable the button and show the reason rather
            // than letting the attempt fail after the fact.
            'deleteState' => $saleReturn->canBeDeleted(auth()->user()),
        ]);
    }

    /**
     * Section 5: deleting a return takes its movements, subtracts each from its
     * batch and deletes them — the reverses_movement_id links restore the earlier
     * state exactly, with no recomputation.
     */
    public function destroy(Request $request, SaleReturn $saleReturn): RedirectResponse
    {
        $sale = $saleReturn->sale;

        try {
            $this->returns->delete($saleReturn, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', __('Return deleted and its stock put back'));
    }
}
