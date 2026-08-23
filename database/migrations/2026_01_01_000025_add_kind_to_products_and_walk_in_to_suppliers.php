<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of row the shop sells that are not ordinary stock.
 *
 * A SECOND-HAND item is one physical thing — this Xbox, bought from this person
 * for this price. Two of the same model are not interchangeable: they were
 * bought on different days for different money and are worth different money,
 * so averaging or queueing them would be wrong. Each gets its own row holding
 * one unit, which makes FIFO exactly right rather than merely tolerable: with a
 * single batch there is nothing to choose between, and the cost recorded
 * against the sale is the price actually paid for that item. Rule 1 is
 * untouched.
 *
 * A SERVICE — creating an email account for someone — has no stock at all.
 * Nothing is bought, nothing is consumed, no batch is opened and no movement is
 * written; the whole price is profit. It is a row here rather than a table of
 * its own so it can sit on a sale beside the goods, which is how it is sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('kind', ['stock', 'used', 'service'])
                ->default('stock')
                ->after('name');

            // What state a second-hand item is in — scratched, no charger, boxed.
            // It is half of what the price is based on, so it belongs with it.
            $table->string('condition_note')->nullable()->after('unit');

            // Who it was bought from. The purchase records this too, but the
            // second-hand list is read constantly and this keeps that one query
            // simple — and outlives a deleted purchase.
            $table->foreignId('acquired_from_id')->nullable()->after('condition_note')
                ->constrained('suppliers')->nullOnDelete();

            $table->index('kind');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            // Someone who walked in and sold the shop their old console is not a
            // supplier in the sense the supplier list means. They are recorded
            // as one so the money works — what is still owed them, their
            // statement, the screen to settle it — and kept off that list.
            $table->boolean('is_walk_in')->default(false)->after('is_active');

            $table->index('is_walk_in');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['acquired_from_id']);
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'condition_note', 'acquired_from_id']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['is_walk_in']);
            $table->dropColumn('is_walk_in');
        });
    }
};
