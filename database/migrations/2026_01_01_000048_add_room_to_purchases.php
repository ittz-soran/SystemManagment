<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a delivery was put — Soran, 2026-09-18.
 *
 * *"add purchase directly to other rooms, but sale always in main"*.
 *
 * ⚠️ **He is reversing his own earlier rule**, which the engine recorded:
 * *"when purchased book at main storage then do transfer to another storage"*.
 * Goods that arrive at the lock-up now say so on the purchase, instead of being
 * booked to the shop floor and carried there on paper afterwards.
 *
 * Nullable, and nullable is the answer for every purchase already recorded: a
 * shop that had no rooms when it typed them did not choose, and inventing "the
 * main room" for them would be a claim nobody made. Read as the main room
 * wherever it is null, which is exactly what those purchases meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            /*
             * `nullOnDelete` rather than cascade: closing a room must never
             * take a purchase's books with it. The batches it opened carry
             * their own room_id and are what the stock is actually counted
             * from; this column only records what was asked for.
             */
            $table->foreignId('room_id')->nullable()->after('supplier_id')
                ->constrained('stock_rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
    }
};
