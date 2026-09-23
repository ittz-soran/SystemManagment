<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a part or a job is guaranteed for — Soran, 2026-09-21.
 *
 * *"warranty before setuped in system for several jobs, for ex scereen
 * replacement have 5 days and battery have 30 days"*. So it is set up once, on
 * the thing itself, and offered from there rather than typed onto every ticket.
 *
 * ⚠️ Nullable, and null means no warranty offered — which is different from
 * zero days. A cable has none; a board repair guaranteed for the rest of the
 * day is 0 and is still a promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_days')->nullable()->after('reorder_level');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('warranty_days');
        });
    }
};
