<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 4: the ONLY way to correct a locked document.
        // Adjustments never touch supplier or customer balances — stock moves,
        // money does not.
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();       // Section 7b: ADJ-NNNNN
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->enum('direction', ['in', 'out']);
            $table->unsignedInteger('quantity');

            // Required for `in`, null for `out`. FIFO needs a cost for every unit,
            // so this cannot be blank on the way in. On the way out the cost comes
            // from the batches consumed.
            $table->unsignedBigInteger('unit_cost')->nullable();

            // Exactly the list in Section 4. Opening stock has no dedicated reason in
            // the doc, so it is recorded as `other` with a note — see Section 13.
            $table->enum('reason', ['damage', 'theft', 'miscount', 'correction', 'other']);
            $table->text('notes')->nullable();
            $table->timestamp('adjusted_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index('product_id');
            $table->index('adjusted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
