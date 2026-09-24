<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A faulty item swapped for the same thing — Soran, 2026-09-23.
 *
 * *"if I have same product I change for him and back this faulty PD-17-UK to
 * supplier and refund, not change inv lines"*.
 *
 * ⚠️ **THE FAULTY UNIT IS ALREADY OUT OF STOCK.** It left when it was sold. It
 * comes back over the counter physically, but the shop's count never saw it
 * again — so a swap moves stock ONCE, and the shop is out only the difference
 * between what the replacement cost and what the faulty one did.
 *
 * ⚠️ Its own document because nothing that existed could do it: a purchase
 * return refuses (the unit is not in its batch), a stock adjustment books the
 * replacement as the shop's own loss with nowhere to put the supplier's
 * credit, and a sale return works but CHANGES THE INVOICE — the one thing this
 * must not do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swaps', function (Blueprint $table) {
            $table->id();

            // Section 7b: every document has a human-readable number.
            $table->string('document_no')->unique();

            /*
             * The line the faulty unit was sold on. ⚠️ Kept as a reference and
             * NOT edited: the customer bought one and still owns one, so the
             * invoice stays true word for word.
             */
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');

            /*
             * What the replacement cost the shop, and what the faulty one did —
             * both frozen here, because the batches they came from will move on.
             * The difference is what the swap cost the shop, and the screen says
             * it rather than hiding it.
             */
            $table->unsignedBigInteger('replacement_cost');
            $table->unsignedBigInteger('faulty_cost');

            // The supplier document raised for the faulty unit. Nullable: a unit
            // with no purchase behind it — opening stock, or carried in from
            // another room — has no supplier to go back to.
            $table->foreignId('purchase_return_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->timestamp('swapped_at', 6);
            $table->text('note')->nullable();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['swapped_at', 'id']);
            $table->index('sale_id');
            $table->index('product_id');
        });

        /*
         * ⚠️ The audit table learns one more word.
         *
         * `reference_type` is the column whose whole job is to say truthfully
         * what moved a unit, and a swap is a new answer: the faulty one going
         * back into its batch, and the replacement leaving the shelf. Writing
         * either as an "adjustment" would avoid this line and would be a lie in
         * the one table that must not contain any — the same reasoning the
         * transfer migration wrote down before it.
         */
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('reference_type', [
                'purchase', 'sale', 'sale_return', 'purchase_return', 'adjustment', 'transfer', 'swap',
            ])->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            /*
             * ⚠️ How many of this line have been swapped rather than returned.
             *
             * The invoice line itself is untouched — same quantity, same price,
             * same printed paper. But a unit already handed back over the
             * counter must not ALSO be returnable, or a second unit that never
             * existed would go onto the shelf. `returnableQuantity()` subtracts
             * this. It is a fact about handling, not about what was sold.
             */
            $table->unsignedInteger('quantity_swapped')->default(0)->after('quantity_returned');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('quantity_swapped');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('reference_type', [
                'purchase', 'sale', 'sale_return', 'purchase_return', 'adjustment', 'transfer',
            ])->change();
        });

        Schema::dropIfExists('swaps');
    }
};
