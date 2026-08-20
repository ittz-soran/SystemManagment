<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 5: this table needs NO batch columns — the movements carry all of it,
        // and one source of truth cannot disagree with itself.
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();

            // REQUIRED. Returns reference the LINE, not the product — the same product
            // on two lines at two prices refunds differently.
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->timestamps();

            $table->index('sale_return_id');
            $table->index('sale_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
    }
};
