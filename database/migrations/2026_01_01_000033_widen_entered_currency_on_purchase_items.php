<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a purchase line name any currency the shop keeps — Section 2b.
 *
 * ⚠️ It was `enum('IQD','USD')`, written when Section 6b's helper was the only
 * thing that could put a currency on a line and USD was the only one it knew.
 * The database itself refuses anything else — and on MariaDB that is an error
 * rather than the silent pass SQLite would give, so a shop adding EUR would
 * have found out at the till rather than in a test.
 *
 * A plain string of eight, the same shape as `currencies.code`. No foreign key:
 * a currency the shop later deletes must not take an old purchase's record of
 * what it was invoiced in with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->string('entered_currency', 8)->default('IQD')->change();
        });
    }

    public function down(): void
    {
        // Anything the enum cannot hold has to go first, or the change fails.
        DB::table('purchase_items')
            ->whereNotIn('entered_currency', ['IQD', 'USD'])
            ->update(['entered_currency' => 'IQD']);

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->enum('entered_currency', ['IQD', 'USD'])->default('IQD')->change();
        });
    }
};
