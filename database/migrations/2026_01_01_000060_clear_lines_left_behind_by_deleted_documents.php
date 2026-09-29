<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 8 — what a deleted assembly and a deleted stock move left behind.
 *
 * Soran, 2026-09-29, once the returns were fixed: *"now delete products are
 * come added from ASM and now ASM is deleted"*. Two different leftovers, both
 * holding a `product_id` with `restrictOnDelete`, both belonging to a document
 * already deleted and so out of every screen's reach:
 *
 *  - **An assembly leaves the stock layers it opened**, emptied. Taking a
 *    bundle apart creates the piece products, and each got a batch; reversing
 *    the movements emptied those batches but left the rows. The shop was told
 *    "This product is on 1 stock batch" — true, and impossible to act on.
 *  - **A stock move leaves its lines.** It removes the layers it opened and
 *    the movements, but never its `stock_transfer_items`.
 *
 * The services no longer leave either. This clears what is already there.
 *
 * ⚠️ **ONLY UNDER A DOCUMENT THAT IS ALREADY DELETED**, and for the batches,
 * only ones that are **empty and have no movement left**. A live document keeps
 * everything it has, and a layer anything has drawn on is never touched.
 *
 * ⚠️ **Nothing in the books moves.** Both services unwind their whole effect
 * before deleting, so what is left is detail of a document that no longer
 * applies. The headers stay, and the audit that reads deleted documents takes
 * only `document_no` from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['assembly_items', 'assemblies', 'assembly_id'],
            ['stock_transfer_items', 'stock_transfers', 'stock_transfer_id'],
        ] as [$lines, $header, $key]) {
            DB::table($lines)
                ->whereIn($key, fn ($q) => $q->select('id')->from($header)->whereNotNull('deleted_at'))
                ->delete();
        }

        // The emptied layers of assemblies that are already deleted — the rows
        // that were holding his Board and CPU.
        DB::table('stock_batches')
            ->where('source_type', 'assembly')
            ->where('quantity_remaining', 0)
            ->whereIn('source_id', fn ($q) => $q->select('id')->from('assemblies')->whereNotNull('deleted_at'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('stock_movements')
                ->whereColumn('stock_movements.stock_batch_id', 'stock_batches.id'))
            ->delete();
    }

    /** As with its predecessor: the rows held nothing that could be rebuilt. */
    public function down(): void {}
};
