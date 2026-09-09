<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each person wants the clock and the date written.
 *
 * Two separate questions, because they have separate answers. A shopkeeper
 * reading the interface in Sorani may still want the date in English — that is
 * how the invoices from his supplier are dated, and how his phone shows it —
 * and the twelve-hour clock is a habit rather than a language.
 *
 * `date_language` defaults to the interface: a shop that chose Kurdish asked
 * for Kurdish, and the person who wants otherwise is the one who will go
 * looking for the setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('date_language', 16)->default('interface')->after('language');
            $table->boolean('clock_24_hour')->default(false)->after('date_language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_language', 'clock_24_hour']);
        });
    }
};
