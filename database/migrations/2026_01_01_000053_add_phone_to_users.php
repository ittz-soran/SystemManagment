<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A number to reach a member of staff on — Soran, 2026-09-22.
 *
 * Arrived with repairs, and needed by them: the repair ticket has always
 * printed the phone number of whoever is mending the device, so the customer
 * can ask about their own phone or television without going through the
 * counter. Repair people used to be rows in a `technicians` table that carried
 * a phone of its own; now they are users, and the number has to live here or
 * stop being printed.
 *
 * Nullable, because most of a shop's users are behind the counter and the
 * number is nobody's business but their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ⚠️ `after()` is ignored by SQLite and obeyed by MariaDB, which is
            // what the shop runs. Placed by the email so the two ways of
            // reaching somebody sit together in a `DESCRIBE`.
            $table->string('phone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
