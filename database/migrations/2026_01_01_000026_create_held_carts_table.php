<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cart put down and picked up later.
 *
 * Twenty-five things scanned and the supplier not chosen yet, or a customer who
 * has gone back to the car for their wallet: the counter needs somewhere to put
 * a cart down that is not the bin.
 *
 * A held cart is a note to self and nothing more. It has no document number, it
 * opens no batch, it moves no stock and it touches no ledger — none of which
 * happens until the real document is saved. That matters more than it sounds:
 * a "reserved" cart holding stock back would hide those units from the sale
 * happening at the other till, and FIFO would be picking from a shelf that
 * disagrees with the room.
 *
 * It lives in the database rather than the browser so it survives a crash, a
 * cleared cache, and the walk to the other computer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_carts', function (Blueprint $table) {
            $table->id();

            // 'sale' or 'purchase' — the screen it belongs to.
            $table->string('type', 16);

            // Who put it down, so the shop knows whose cart it is.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // What it was for: "Karwan's laptop order", "waiting on the invoice".
            $table->string('note')->nullable();

            /*
             * The lines, and whoever was chosen so far. Only what was typed:
             * product, quantity, price. Stock and cost are deliberately NOT
             * kept — they move while the cart sits, and a cart resumed tomorrow
             * must show tomorrow's shelf, not a photograph of yesterday's.
             */
            $table->json('payload');

            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_carts');
    }
};
