<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one switch the remembrances need.
 *
 * ⚠️ Per person, not per shop, and off means off for that person alone. A shop
 * has one owner and several staff, and what somebody says at their own counter
 * is not a setting for an admin to make on their behalf — so this sits beside
 * language and theme in Section 8c layer 3, not in Settings.
 *
 * Nothing else is stored. The adhkar themselves are lines in a setting, like
 * the units list, and the tally beside each one lives in the reader's own
 * browser for today only — see Adhkar and the script in app.js for why neither
 * of those is a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Opt out, not opt in. Soran asked for this section; somebody who
            // does not want it says so once, and everybody else has it without
            // having to find a switch first.
            $table->boolean('adhkar_off')->default(false)->after('notify_tiers');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('adhkar_off');
        });
    }
};
