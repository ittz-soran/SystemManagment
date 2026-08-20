<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Section 4 SKU rules: typed manufacturer code, or auto `SS` + counter.
            // Both kinds live here, unique across all products.
            $table->string('sku')->unique();

            // Section 4 barcode rules: scanned/typed, or auto EAN-13 in prefix 200-299.
            $table->string('barcode', 32)->nullable()->unique();

            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('unit')->default('pcs');

            // Section 4: these two are only default suggestions for the cart.
            // Section 2: IQD is whole numbers, stored as integer BIGINT, never decimal.
            $table->unsignedBigInteger('purchase_price')->default(0);
            $table->unsignedBigInteger('sale_price')->default(0);

            // Section 4: a CACHE, not the truth. The truth is SUM(stock_batches.quantity_remaining).
            // Always rewritten as that SUM inside the movement's own transaction, never quantity +/- n.
            $table->integer('quantity')->default(0);

            // Null falls back to settings.low_stock_threshold (Section 8c).
            $table->unsignedInteger('reorder_level')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
