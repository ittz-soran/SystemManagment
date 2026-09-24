<?php

use App\Models\StockBatch;
use App\Models\StockMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The pieces a take-apart made said they were adjustments — 2026-09-24.
 *
 * ⚠️ `FifoService::createBatch` chose between two words: a purchase, or else an
 * adjustment. That was true for as long as a batch could only be born of those
 * two — and the day take-apart shipped, "else" became a lie in the one table
 * whose whole job is to say truthfully what moved a unit. Every piece a
 * take-apart created was written as an `adjustment` row pointing at an assembly
 * id: the product page looked up an adjustment that was not there, and undoing
 * the document could not find its own movements to reverse.
 *
 * Fixed at the source the same day. This puts right the rows written in the
 * hours before that, which is a small number on one shop and none anywhere
 * else — but a mislabelled audit row is exactly the kind of thing nobody finds
 * later, and it is precisely identifiable: an adjustment movement sitting on a
 * batch whose source is an assembly can be nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        $batches = DB::table('stock_batches')
            ->where('source_type', StockBatch::SOURCE_ASSEMBLY)
            ->pluck('id');

        if ($batches->isEmpty()) {
            return;
        }

        DB::table('stock_movements')
            ->whereIn('stock_batch_id', $batches)
            ->where('reference_type', StockMovement::REF_ADJUSTMENT)
            ->update(['reference_type' => StockMovement::REF_ASSEMBLY]);
    }

    public function down(): void
    {
        // Deliberately nothing. Putting the lie back would be the only thing
        // this could do, and a migration that corrupts an audit table on the
        // way down is worse than one that is not reversible.
    }
};
