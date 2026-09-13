<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which currency this person reads figures in — Section 2b, Section 8c layer 3.
 *
 * A preference rather than a shop setting, decided with Soran on 2026-09-13:
 * the same shape as language and theme, which already belong to the person.
 * Soran reads his purchases in dollars; the person at the counter does not, and
 * neither choice should reach the other.
 *
 * ⚠️ Null means the base currency, and null is the default. A shop that never
 * touches this reads exactly what it read before — which is what makes the lens
 * safe to ship before every screen offers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('display_currency', 8)->nullable()->after('cost_markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('display_currency');
        });
    }
};
