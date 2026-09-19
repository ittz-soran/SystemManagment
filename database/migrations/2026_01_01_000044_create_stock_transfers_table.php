<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving goods between rooms — Soran, 2026-09-15.
 *
 * A document, like a purchase or a sale, because that is what it is: somebody
 * carried twelve cartons from the shopfront to the back room on a Tuesday, and
 * in a month the shop will want to know who and when.
 *
 * ⚠️ **A transfer moves nothing in or out of the shop, and must not look as
 * though it did.** No supplier, no customer, no money, no ledger entry, no
 * effect on what anything cost. It writes two stock movements — units out of a
 * batch in one room, the same units into a new batch in another — which net to
 * zero, so `SUM(quantity)` per product still equals current stock and the
 * integrity check stays true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();

            // Section 7b: every document has a human-readable number.
            $table->string('document_no')->unique();

            $table->foreignId('from_room_id')->constrained('stock_rooms')->restrictOnDelete();
            $table->foreignId('to_room_id')->constrained('stock_rooms')->restrictOnDelete();

            // Microsecond precision, for the same reason as every other document
            // in this system: two transfers in the same second must still order.
            $table->timestamp('transferred_at', 6);

            $table->string('note')->nullable();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['transferred_at', 'id']);
            $table->index('from_room_id');
            $table->index('to_room_id');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // Line order, the same idea as a purchase: the same product may not
            // appear twice, but the order the shopkeeper typed is worth keeping.
            $table->unsignedInteger('sequence')->default(0);

            $table->timestamps();

            $table->index(['stock_transfer_id', 'sequence']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
