<?php

namespace App\Http\Controllers;

use App\Models\Swap;
use App\Services\SwapService;
use Illuminate\Contracts\View\View;
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
    public function __construct(private SwapService $swaps) {}

    public function index(Request $request): View
    {
        return view('swaps.index', [
            'lens' => $request->user()->lens(),
            'swaps' => Swap::with('sale.customer', 'product', 'purchaseReturn')
                ->orderByDesc('swapped_at')->orderByDesc('id')
                ->paginate($request->user()->items_per_page),
        ]);
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
        ]);
    }

    /**
     * The note, and nothing else.
     *
     * ⚠️ A swap is a fact about a physical handover. A different quantity, or a
     * different line, is a different swap — and pretending otherwise behind an
     * Edit button would leave the stock saying one thing and the document
     * another. Correcting what somebody typed is worth having; rewriting what
     * happened is delete and do it again.
     */
    public function update(Request $request, Swap $swap): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $swap->update(['note' => $data['note'] ?? null]);

        return redirect()->route('swaps.show', $swap)->with('success', __('Note saved'));
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
