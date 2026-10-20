<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of customer — PROJECT_DOC Section 9, Soran, 2026-10-20.
 *
 * *"my customers have some types -> cash customer (allready i have) goverment
 * customers (i want show as deferet list in customers) ... and this type need
 * A5 invoice. and normal customers"*
 *
 * The Cash Customer stays its own thing, `is_system`; this sorts the rest into
 * normal and government. Every customer that exists today is normal, which is
 * what the default writes into every row on the way past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('type', 16)->default('normal')->after('is_system')->index();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
