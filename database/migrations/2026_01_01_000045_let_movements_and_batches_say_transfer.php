<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two enums learn the word "transfer".
 *
 * ⚠️ **This touches the audit table, so it is the smallest change that works.**
 * `stock_movements.reference_type` and `stock_batches.source_type` are the two
 * columns that say where a row came from, and a transfer is a new answer to
 * both: a layer whose source is a transfer rather than a purchase, and the pair
 * of movements that carried it there.
 *
 * Writing a transfer as an "adjustment" instead would have avoided this
 * migration and would have been a lie in the one table whose whole job is to
 * say truthfully what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('reference_type', [
                'purchase', 'sale', 'sale_return', 'purchase_return', 'adjustment', 'transfer',
            ])->change();
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->enum('source_type', ['purchase', 'adjustment', 'transfer'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('reference_type', [
                'purchase', 'sale', 'sale_return', 'purchase_return', 'adjustment',
            ])->change();
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->enum('source_type', ['purchase', 'adjustment'])->change();
        });
    }
};
