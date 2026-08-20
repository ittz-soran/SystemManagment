<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 4: one layer of stock at one cost.
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Every batch is traceable to where it came from: a purchase line, or an
            // `in` stock adjustment. Never both, never neither.
            $table->enum('source_type', ['purchase', 'adjustment']);
            $table->unsignedBigInteger('source_id');

            // Null for adjustment batches. Exists as a direct link because purchase
            // returns look up the batch by purchase line.
            $table->foreignId('purchase_item_id')->nullable()
                ->constrained()->cascadeOnDelete();

            // Section 6: exactly the price typed on that purchase line. Always.
            $table->unsignedBigInteger('unit_cost');

            $table->unsignedInteger('quantity_in');

            // Unsigned: the database refuses to let a batch go negative.
            // A batch that reaches 0 is NOT finished — a return can refill it.
            $table->unsignedInteger('quantity_remaining');

            $table->timestamp('received_at');

            // Section 4: line order within the purchase. The same product can appear
            // twice in one purchase at two costs, sharing a timestamp — without this,
            // FIFO order is undefined.
            $table->unsignedInteger('sequence')->default(0);

            $table->timestamps();

            // Section 8b: the FIFO lookup. Runs on every sale line.
            $table->index(
                ['product_id', 'quantity_remaining', 'received_at', 'sequence'],
                'stock_batches_fifo_index'
            );
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
