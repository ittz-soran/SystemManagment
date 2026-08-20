<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();

            // REQUIRED. One purchase_item maps to exactly one batch, so there is no
            // ordering question — deduct from that batch and write one movement.
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');

            // Section 7: the credit, pre-filled at the full typed unit price.
            $table->unsignedBigInteger('unit_price');

            // Section 7: defaults to 0, NOT to the calculated share. Soran decides
            // per return whether the supplier credits proportionally.
            $table->unsignedBigInteger('discount_share')->default(0);

            $table->timestamps();

            $table->index('purchase_return_id');
            $table->index('purchase_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};
