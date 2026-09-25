<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsGoodsComingBack;
use App\Models\Swap;
use App\Services\SwapService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * The swap documents, and what can still be done to one.
 *
 * ⚠️ **Swaps are no longer STARTED here** — Soran, 2026-09-25: *"remove page
 * swaps because i use goods-back it"*. `Goods coming back` asks the one
 * question this screen used to ask in three states, and asks it once for all
 * three documents, so keeping a second way in would mean two screens to teach,
 * two to translate and two to keep true.
 *
 * What is left is the history: the list, the document, its note, and deleting
 * one. `/swaps/create` still answers, as a redirect, so a bookmark or a
 * printed link does not land on a missing page.
 */
class SwapController extends Controller
{
    use ListsGoodsComingBack;

    public function __construct(private SwapService $swaps) {}

    public function index(Request $request): View
    {
        $filtered = $this->cameBack(Swap::query(), $request, 'swapped_at');

        return view('swaps.index', [
            'lens' => $request->user()->lens(),
            'swaps' => (clone $filtered)->with('sale.customer', 'product', 'purchaseReturn')
                ->orderByDesc('swapped_at')->orderByDesc('id')
                ->paginate($request->user()->items_per_page)
                ->withQueryString(),

            // Section 8c: the toggle only appears when something is hidden.
            'archivedCount' => (int) Swap::archivedOnly()->count(),
            'isFiltered' => $this->isFiltered($request),
            'stats' => $this->figures($filtered, $request),
        ]);
    }

    /**
     * The same four questions the two return lists ask, in the one vocabulary
     * where the third has no column to read.
     *
     * ⚠️ **A swap has no total.** The invoice behind it was not changed, so
     * there is no `total_amount` to sum — what a swap is worth to the shop is
     * `replacement_cost - faulty_cost`, the dearer layer the replacement came
     * off less what the supplier gave back. A tile showing the sale price here
     * would be exactly the mistake the P&L made before `TradeProfit` learned
     * about swaps: money the shop never made.
     *
     * @param  Builder<Swap>  $filtered
     * @return array<int, array{label: string, value: string, note: string}>
     */
    private function figures($filtered, Request $request): array
    {
        $lens = $request->user()->lens();

        $count = (int) (clone $filtered)->count();
        $units = (int) (clone $filtered)->sum('quantity');
        $cost = (int) (clone $filtered)->sum('replacement_cost') - (int) (clone $filtered)->sum('faulty_cost');

        // How many were the supplier's problem rather than the shop's. A swap
        // with no purchase behind the faulty unit is one the shop carried.
        $billed = (int) (clone $filtered)->whereNotNull('purchase_return_id')->count();

        return [
            [
                'label' => __('Swaps'),
                'value' => number_format($count),
                'note' => __('documents on this list'),
            ],
            [
                'label' => __('Units handed over again'),
                'value' => number_format($units),
                'note' => __('the same thing, a second time'),
            ],
            [
                'label' => __('What it cost the shop'),
                'value' => money($cost, in: $lens),
                'note' => $cost < 0
                    ? __('the replacements came off cheaper layers')
                    : __('the replacements less what came back'),
            ],
            [
                'label' => __('Billed to a supplier'),
                'value' => number_format($billed),
                'note' => trans_choice(
                    '{0}the shop carried none of them|{1}the shop carried the other one'
                    .'|[2,*]the shop carried the other :count',
                    max(0, $count - $billed), ['count' => number_format(max(0, $count - $billed))],
                ),
            ],
        ];
    }

    public function show(Request $request, Swap $swap): View
    {
        return view('swaps.show', [
            'lens' => $request->user()->lens(),
            'swap' => $swap->load('sale.customer', 'saleItem', 'product', 'purchaseReturn.purchase.supplier', 'user'),

            // Section 8: computed live and re-checked inside the transaction.
            // The page disables the button and prints the reason rather than
            // letting the attempt fail after the fact.
            'deleteState' => $swap->canBeDeleted($request->user()),

            // The same shape, asked of the other button. A swap that cannot be
            // undone cannot be corrected either, and for the same reason.
            'changeState' => $swap->canBeChanged($request->user()),

            // The most it could be raised to: what is left on the line, plus
            // what this swap itself is holding, and never more than the shelf
            // could hand over once its own replacement is back on it.
            'mostItCouldBe' => $this->mostItCouldBe($swap),
        ]);
    }

    /**
     * The note on its own, or the note and how many were handed over.
     *
     * ⚠️ **A quantity change is not an edit of this row; it is the swap undone
     * and done again** — see `SwapService::update()`. So the two go down
     * different roads on purpose: a note is written straight onto the row,
     * because a note is a sentence ABOUT the handover, while a quantity IS the
     * handover and has to move the stock with it.
     *
     * The quantity only arrives from somebody who may change it. A form that
     * did not offer the field still cannot post one, because the service asks
     * `canBeChanged()` again for itself.
     */
    public function update(Request $request, Swap $swap): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $wanted = (int) ($data['quantity'] ?? $swap->quantity);

        if ($wanted === (int) $swap->quantity) {
            $swap->update(['note' => $data['note'] ?? null]);

            return redirect()->route('swaps.show', $swap)->with('success', __('Note saved'));
        }

        try {
            $this->swaps->update(
                swap: $swap,
                quantity: $wanted,
                user: $request->user(),
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException|Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('swaps.show', $swap)
            ->with('success', __('Swap :number is now :count', [
                'number' => $swap->document_no,
                'count' => trans_choice('{1}one unit|[2,*]:count units', $wanted, ['count' => number_format($wanted)]),
            ]));
    }

    /**
     * The biggest figure this swap could be corrected to.
     *
     * ⚠️ **Counted with the swap itself undone**, because that is the state a
     * correction starts from: raising one to two needs the shelf to hold a
     * second replacement, and the first replacement is coming back before the
     * second goes out. Counted the other way round, a shop with exactly one
     * spare could never correct a swap it had just made.
     */
    private function mostItCouldBe(Swap $swap): int
    {
        $line = $swap->saleItem;
        $product = $swap->product;

        if ($line === null || $product === null || ! $product->tracksStock()) {
            return (int) $swap->quantity;
        }

        // What the line could give back once this swap has let go of its own.
        $onTheLine = $line->returnableQuantity() + (int) $swap->quantity;

        // And what the shelf would hold with this swap's replacement back on it.
        $onTheShelf = (int) $product->quantity + (int) $swap->quantity;

        return max(1, min($onTheLine, $onTheShelf));
    }

    public function destroy(Request $request, Swap $swap): RedirectResponse
    {
        try {
            $this->swaps->delete($swap, $request->user());
        } catch (RuntimeException|Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('swaps.index')
            ->with('success', __('Swap :number deleted', ['number' => $swap->document_no]));
    }
}
