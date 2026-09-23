<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer was promised when they bought it — Soran, 2026-09-23.
 *
 * *"i sell this 1 month ago PD-17-UK now not working customer back it to change
 * on warenty"*. The shop could not answer that from the system: `warranty_days`
 * lived on the product and only repairs ever read it.
 *
 * ⚠️ **Copied onto the line, not read through the product** — the same rule a
 * repair line already follows, for the same reason. A warranty is a promise
 * made on a particular day, and somebody editing the product next month must
 * not change what the invoice in the customer's hand says.
 *
 * Nullable, and null means no warranty was offered — which is different from
 * zero days. A cable has none; a board guaranteed for the rest of the day is 0
 * and is still a promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // ⚠️ `after()` is ignored by SQLite and obeyed by MariaDB, which is
            // what the shop runs. Beside the price, which is the other thing
            // frozen onto the line at the moment of sale.
            $table->unsignedSmallInteger('warranty_days')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('warranty_days');
        });
    }
};
