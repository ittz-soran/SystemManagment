<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How loud a phone is allowed to be — separate from the bell.
 *
 * ⚠️ **Its own setting, not the bell's.** A number on a badge and a buzz in a
 * pocket at eleven at night are not the same event, and somebody may well want
 * everything on the bell and only the serious things on the phone.
 *
 * Defaulted to `alert,news` because that is what Soran asked for by example —
 * *"for ex edited an product success, should recived notify"* — and a product
 * edit is news. Anybody who finds that too much turns it down to alerts in two
 * taps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('push_tiers', 64)->default('alert,news')->after('adhkar_every');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('push_tiers');
        });
    }
};
