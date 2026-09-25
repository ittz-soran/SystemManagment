<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The three lists of things that came back, filtered the one way — Soran,
 * 2026-09-25: *"make all three purchase-returns, sale-returns, swaps have same
 * designs or same like one"*.
 *
 * ⚠️ **The filtering was three copies of the same six lines**, and the swap
 * list had none of them at all — the way a copied block goes missing, by
 * nobody noticing a page that is different from nothing in particular. The
 * only thing that genuinely differs between the three is which column holds
 * the date, so that is the one thing this takes as an argument.
 */
trait ListsGoodsComingBack
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    private function cameBack(Builder $query, Request $request, string $dateColumn): Builder
    {
        return $query
            // An archived period stays in the database and out of this list,
            // unless the reader asks for it.
            ->visible($request->boolean('archived'))
            ->when($request->filled('search'), fn ($q) => $q->where('document_no', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate($dateColumn, '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate($dateColumn, '<=', $request->date('to')));
    }

    /**
     * What of these documents was settled in cash rather than against a balance.
     *
     * ⚠️ **`payable_type` holds the MORPH ALIAS, not the class name** — this
     * shop has a morph map, so the column reads `sale_return`, never
     * `App\Models\SaleReturn`. Written the obvious way, with `::class`, the
     * query matches nothing and the tile reads a confident zero: the first
     * version of this said *"120,000 came off what they owed"* about a customer
     * who had never owed a dinar. A figure that is wrong is bad; a figure that
     * is wrong and explains itself is worse, because it answers the doubt that
     * would have caught it. `getMorphClass()` is the alias, whatever the map
     * says today.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $documents
     */
    private function settledInCash(Builder $documents, Model $kind): int
    {
        return (int) Payment::where('payable_type', $kind->getMorphClass())
            ->whereIn('payable_id', (clone $documents)->select('id'))
            ->sum('amount');
    }

    /**
     * Whether the reader has narrowed the list.
     *
     * ⚠️ Read by the figures strip to say which of its two sentences applies,
     * and that sentence is the whole reason the figures can be trusted. The
     * archived toggle counts: showing the archived period changes every one of
     * the four.
     */
    private function isFiltered(Request $request): bool
    {
        return $request->filled('search')
            || $request->filled('from')
            || $request->filled('to')
            || $request->boolean('archived');
    }
}
