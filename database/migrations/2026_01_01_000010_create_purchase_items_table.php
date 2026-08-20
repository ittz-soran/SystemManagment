<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 4: the same product may appear on two lines at two different prices —
        // supported, NEVER merged. Each line becomes its own stock_batches row.
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');

            // Section 6: this exact number becomes the batch cost. Nothing changes it.
            $table->unsignedBigInteger('unit_price');

            // Section 7: returnable = quantity - quantity_returned, cumulative.
            $table->unsignedInteger('quantity_returned')->default(0);

            // Section 6b: reference only, never used in any calculation.
            $table->enum('entered_currency', ['IQD', 'USD'])->default('IQD');
            $table->unsignedBigInteger('entered_amount')->nullable();

            // Section 4: line order within the purchase. Without it, two lines for the
            // same product sharing a timestamp have undefined FIFO order.
            $table->unsignedInteger('sequence');

            $table->timestamps();

            $table->index('purchase_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
