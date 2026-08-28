<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each member of staff is allowed to see a thing cost.
 *
 * Permissions answer "may they open this screen". This answers a different
 * question the shop actually asks: the person at the counter needs the products
 * list, and the products list has a purchase price on every row. Withholding
 * the screen is not an option — it is the screen they work from all day.
 *
 * So the figure itself is what varies. Three settings, per person:
 *
 *   real    what it cost. The default, and what an admin always gets.
 *   markup  what it cost plus a percentage the admin sets, so the counter
 *           works from a number that is wrong in the shop's favour and never
 *           learns the real margin.
 *   hidden  *****
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('cost_visibility', ['real', 'markup', 'hidden'])
                ->default('real')
                ->after('role');

            // Whole per cent. 10 shows a 1,000 IQD cost as 1,100.
            $table->unsignedSmallInteger('cost_markup_percent')
                ->default(0)
                ->after('cost_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cost_visibility', 'cost_markup_percent']);
        });
    }
};
