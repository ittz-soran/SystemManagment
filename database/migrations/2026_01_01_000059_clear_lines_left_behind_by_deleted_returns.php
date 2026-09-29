<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 8, "A deleted document's lines outlived it" — Soran, 2026-09-29.
 *
 * Deleting a return reversed everything that mattered and soft-deleted the
 * return, but left its lines behind. Those lines point at `purchase_items` and
 * `sale_items` with `restrictOnDelete`, so deleting the PURCHASE or SALE later
 * failed on the foreign key — and put the database's own words on his screen:
 *
 *   SQLSTATE[23000] … purchase_return_items_purchase_item_id_foreign …
 *   delete from `purchase_items` where `purchase_id` = 48
 *
 * The services no longer leave them, but this fault has been shipping, so a
 * shop already running has these rows sitting under its deleted returns. This
 * clears exactly those.
 *
 * ⚠️ **ONLY UNDER A RETURN THAT IS ALREADY DELETED.** A live return keeps every
 * line it has. Nothing here touches a document anybody can still see.
 *
 * ⚠️ **Nothing in the books moves.** These lines carry no stock and no money:
 * the movements were removed, `quantity_returned` was put back, the ledger was
 * reversed and the refund was un-paid, all at the moment the return was
 * deleted. What is left is detail of a document that no longer applies, which
 * nothing reads — the audit that reads deleted documents takes only
 * `document_no` from the header, and the header stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['purchase_return_items', 'purchase_returns', 'purchase_return_id'],
            ['sale_return_items', 'sale_returns', 'sale_return_id'],
        ] as [$lines, $header, $key]) {
            DB::table($lines)
                ->whereIn($key, fn ($q) => $q->select('id')->from($header)->whereNotNull('deleted_at'))
                ->delete();
        }
    }

    /**
     * There is no down. The rows held nothing that could be reconstructed and
     * nothing that anything read; writing them back would be inventing them.
     */
    public function down(): void {}
};
