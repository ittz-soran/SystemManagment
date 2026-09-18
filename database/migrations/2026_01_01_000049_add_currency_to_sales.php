<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale written in another currency — Soran, 2026-09-19.
 *
 * *"if currency on usd change sale page to usd, but in sale page have combo to
 * change again and input to rate"*.
 *
 * ⚠️ **This reverses decision 3b, which §2b recorded as deliberate:** *"Sales
 * carry no `exchange_rate` column, so a sale cannot be written in a foreign
 * currency… That follows from decision 3b — you sell across a counter in
 * dinars — and is not an omission."* It is an omission now, because Soran sells
 * phones priced in dollars.
 *
 * ⚠️ **What has NOT changed, and must not: only base-currency integers are
 * stored.** `unit_price`, `total_amount`, `discount_amount` and `grand_total`
 * are dinars exactly as they always were, so FIFO, the ledger, every report and
 * every balance are untouched. These three columns record what somebody TYPED,
 * so the document can show it back and an edit can reopen the box the way they
 * left it — the same three the purchase side has carried since §6b.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            /*
             * ⚠️ A WHOLE number of base units per one foreign unit, matching
             * `purchases.exchange_rate` exactly. The currencies table can carry
             * 1,320.125 for reading; a document records what was typed into its
             * own box, and a different width here would mean two columns that
             * look the same and are not.
             *
             * Null is a sale written in the shop's own money, which is every
             * sale recorded before today.
             */
            $table->unsignedInteger('exchange_rate')->nullable()->after('grand_total');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            /*
             * `string(8)` with NO foreign key, for the reason the purchase side
             * has one: a currency the shop later deletes must not take an old
             * document's record of what it was written in with it.
             */
            $table->string('entered_currency', 8)->nullable()->after('unit_price');
            $table->unsignedBigInteger('entered_amount')->nullable()->after('entered_currency');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('exchange_rate'));
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['entered_currency', 'entered_amount']));
    }
};
