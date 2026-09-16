<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often a remembrance shows itself.
 *
 * **Soran, 2026-09-16:** *"i want every 1 min or 5 min show on of Remembrances
 * as notification show on screen, without user go to read Remembrance
 * manualy"*.
 *
 * ⚠️ This reverses an earlier rule of his — *"never a dialog over the till"* —
 * and it is stored as MINUTES rather than built in, because he said "1 min or 5
 * min" and was plainly deciding as he wrote. A number he can change is the
 * honest answer to somebody who has not settled on one; so is 0, which is off.
 *
 * Five rather than one by default. One minute is four hundred and eighty of
 * these in a working day, which is the amount of anything that turns it into
 * wallpaper — and a shopkeeper who wants that can say so in two clicks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('adhkar_every')->default(5)->after('adhkar_off');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('adhkar_every');
        });
    }
};
