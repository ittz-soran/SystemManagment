<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A name written on a walk-in sale — Soran, 2026-09-27.
     *
     * ⚠️ **A note, not a customer.** Somebody who gives a name at the counter
     * is not an account: no balance, no history, nothing owed. A `customers`
     * row for each of them would fill the list with people who bought one
     * cable, and every one would carry a balance to reconcile forever.
     *
     * So: one nullable column on the sale, written on the invoice and nowhere
     * else. It is only ever set on a sale to the Cash Customer — a named
     * customer already has a name, and two answers to one question is worse
     * than none.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('walk_in_name', 100)->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('walk_in_name');
        });
    }
};
