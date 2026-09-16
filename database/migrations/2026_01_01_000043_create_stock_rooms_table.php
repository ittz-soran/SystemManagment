<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock rooms — asked for by Soran, 2026-09-15.
 *
 * *"Store, one shop with several stoke-rooms or storage rooms"*, and then the
 * rule that shapes everything: *"No pos or sale always user mainstore or
 * mainstorage while sale, second storage just holds that products are can hold
 * in main storage, and when purchased book at main storage then do transfer to
 * another storage"*.
 *
 * So this is a hub and spokes, not a mesh:
 *
 *   ONE room sells. Every sale, every write-off, every till draws from the main
 *   room and only the main room. A back room holds overflow and nothing more.
 *
 *   Purchases land in main. Goods arrive at the shop, are booked in, and are
 *   moved out afterwards if there is no space.
 *
 *   Transfers go any room to any room (his answer, 2026-09-15), because a shop
 *   with two back rooms should not have to route a crate through the shopfront
 *   to move it between them.
 *
 * ⚠️ **A room is not a second stock system.** It is one column on the batch,
 * and the batch is still the one layer of stock at one cost that Section 4
 * describes. Everything that made FIFO right stays exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            /*
             * ⚠️ The one room that sells, and there is exactly one.
             *
             * Not a setting pointing at an id, because a setting can point at a
             * room somebody has since deleted and the till would have nowhere
             * to draw from. A flag on the row cannot dangle.
             */
            $table->boolean('is_main')->default(false);

            // A room being wound down: it still holds what it holds and still
            // shows on reports, but nothing new can be transferred into it.
            $table->boolean('is_active')->default(true);

            $table->string('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_main', 'is_active']);
        });

        /*
         * Every shop gets its main room on the day this runs, named for what it
         * is. A shop that has been trading for months already has stock, and
         * that stock is in the shop — so it belongs to this room, which is what
         * the backfill below says.
         */
        $now = now();

        $mainId = DB::table('stock_rooms')->insertGetId([
            'name' => 'Main store',
            'is_main' => true,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('stock_batches', function (Blueprint $table) {
            // Nullable for the length of this migration only — the backfill
            // below fills it and the column is made required after.
            $table->foreignId('room_id')->nullable()->after('product_id')
                ->constrained('stock_rooms')->restrictOnDelete();

            /*
             * Where a transferred layer came from.
             *
             * A transfer SPLITS a batch: it takes units out of one and makes a
             * new one in the destination room at the same cost and the same
             * received_at, so FIFO in the far room still reflects the order the
             * shop actually bought things. This keeps the chain walkable —
             * without it a transferred layer has no way back to the purchase it
             * came from.
             */
            $table->foreignId('parent_batch_id')->nullable()->after('purchase_item_id')
                ->constrained('stock_batches')->restrictOnDelete();

            /*
             * Section 8b: the FIFO lookup now asks for one room.
             *
             * A second index rather than a change to the existing one: that one
             * serves "everything this product owns", which the reorder level
             * and every report still ask for, and Soran's answer on 2026-09-15
             * was that the reorder level stays on what the shop owns rather
             * than on what is within reach.
             */
            $table->index(
                ['product_id', 'room_id', 'quantity_remaining', 'received_at', 'sequence'],
                'stock_batches_room_fifo_index'
            );
        });

        // Everything the shop already holds is in the shop.
        DB::table('stock_batches')->update(['room_id' => $mainId]);

        Schema::table('stock_batches', function (Blueprint $table) {
            // ⚠️ Required from here on. A batch with no room is stock nobody can
            // find, and the till would silently stop seeing it.
            $table->unsignedBigInteger('room_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropIndex('stock_batches_room_fifo_index');
            $table->dropConstrainedForeignId('parent_batch_id');
            $table->dropConstrainedForeignId('room_id');
        });

        Schema::dropIfExists('stock_rooms');
    }
};
