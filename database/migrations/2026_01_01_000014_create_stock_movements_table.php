<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 4: the complete audit of every unit in and out.
        // SUM(quantity) per product must equal current stock. That is the check
        // that proves the books are intact.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete();

            // A purchase also writes movements — creating the batch is not enough.
            $table->enum('reference_type', [
                'purchase', 'sale', 'sale_return', 'purchase_return', 'adjustment',
            ]);
            $table->unsignedBigInteger('reference_id');

            // REQUIRED for sales and both return types. Points at the sale_item /
            // purchase_item row, not just the parent document. One sale can list the
            // same product on two lines at two prices, and both may draw from the same
            // batch — without this, per-line returns are impossible.
            $table->unsignedBigInteger('reference_item_id')->nullable();

            // Every return movement points at the exact sale movement it undoes.
            // Without it, repeated partial returns silently corrupt the cost layers.
            $table->foreignId('reverses_movement_id')->nullable()
                ->constrained('stock_movements')->restrictOnDelete();

            // Signed: positive is in, negative is out.
            $table->integer('quantity');

            // Copied from the batch (or, for a return, from the movement being
            // reversed) so the COGS reversal equals the COGS recorded, to the dinar.
            $table->unsignedBigInteger('unit_cost');

            // Microsecond precision, for the same reason as stock_batches.received_at.
            $table->timestamp('occurred_at', 6);

            // Order FIFO by received_at + sequence — NEVER by id. Sale returns walk
            // this column DESC to give units back in reverse order of consumption.
            $table->unsignedInteger('sequence')->default(0);

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            // Section 8b indexes.
            $table->index(['product_id', 'occurred_at', 'sequence']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('stock_batch_id');
            $table->index(['reference_type', 'reference_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
