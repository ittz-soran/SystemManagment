<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The currencies a shop can type and read in — PROJECT_DOC Section 2b.
 *
 * ⚠️ **This is not a step towards storing money in more than one currency.**
 * Section 6b's rule holds exactly as written: only the base currency is ever
 * stored, and a foreign currency is a calculator on the way in and a lens on
 * the way out. Nothing in this table appears on a purchase, a batch or a
 * balance — it decides what a screen draws and what its boxes expect.
 *
 * The base currency is a row here too, and that is what makes one formula
 * serve both jobs. Its `decimals` is also where the redenomination lives: the
 * day the dinar loses three zeros, IQD goes from 0 decimals to 3 and every
 * figure in the system reads as dinars-and-fils. That used to be a loose
 * setting (`currency_minor_per_major`); it belongs on the currency it
 * describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();

            // ISO 4217 where one exists, because that is what a supplier's
            // invoice and every exchange board already say.
            $table->string('code', 8)->unique();
            $table->string('name');

            // Shown after a figure when set. Falls back to the code, which is
            // never wrong and is what IQD already does through __('IQD').
            $table->string('symbol', 8)->nullable();

            /*
             * How many minor units make one major: USD 2 (cents), IQD 0 today.
             *
             * For the BASE currency this is also what every stored integer
             * counts — see App\Support\Money.
             */
            $table->unsignedTinyInteger('decimals')->default(0);

            /*
             * How many base MINOR units one MAJOR unit of this currency is
             * worth, times 1,000.
             *
             *     1 USD = 1,320 IQD   →   1_320_000
             *
             * ⚠️ An integer, because Section 6 allows no decimal columns — and
             * scaled by a thousand so a rate of 1,320.125 survives. Three
             * decimal places is well past what a money-changer quotes, and the
             * scale is small enough that the reverse conversion of the largest
             * amount this system can hold does not overflow a 64-bit integer.
             * Money::RATE_SCALE is the one place that knows the number.
             *
             * The base currency's own rate is 10^decimals × 1,000, which is
             * what makes one conversion formula serve every currency including
             * the base itself.
             */
            $table->unsignedBigInteger('rate')->default(1000);

            // Off rather than deleted: a shop that stops buying in dollars
            // still has purchases whose recorded rate says USD.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
