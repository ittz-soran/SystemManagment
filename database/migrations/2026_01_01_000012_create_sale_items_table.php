<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 4: the same product may appear on two lines at two different prices —
        // supported, NEVER merged. Same rule as purchase_items.
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');

            // Section 7: the refund uses THIS line's unit price — the same product on
            // two lines refunds differently.
            $table->unsignedBigInteger('unit_price');

            $table->unsignedInteger('quantity_returned')->default(0);

            $table->unsignedInteger('sequence');

            $table->timestamps();

            $table->index('sale_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
