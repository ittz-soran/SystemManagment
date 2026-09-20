<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workshop book — Soran, 2026-09-20.
 *
 * A phone comes in broken and leaves mended, and everything about that week is
 * on paper today. *Services* is often mistaken for this: it is a price line
 * added to a sale and records the money, never the job.
 *
 * ⚠️ **THE MONEY AND THE STOCK HAPPEN ONCE, AT COLLECTION, THROUGH AN ORDINARY
 * SALE.** The parts a job needs are held here as lines and are NOT taken out of
 * stock when they are fitted. Collecting creates a normal `Sale` carrying those
 * parts plus the labour, and that is what consumes FIFO, posts to the ledger,
 * takes payment and appears in the P&L at its true cost.
 *
 * Moving stock when a part is fitted and billing separately would be a second
 * implementation of FIFO, and Section 5 is the part of this system least able
 * to afford one. The cost of the choice, stated rather than hidden: a screen
 * already inside a customer's phone still counts as on the shelf until the job
 * is collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repairs', function (Blueprint $table) {
            $table->id();

            // Section 7b: every document has a human-readable number.
            $table->string('document_no')->unique();

            // A walk-in is the Cash Customer, exactly as a sale takes one.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // The device as words, and its identifier as typed. Not a product
            // and not tracked per unit — an IMEI written on a ticket is what a
            // shop actually has, and inventing a serial register to hold it
            // would be a far larger change than this one.
            $table->string('device');
            $table->string('identifier')->nullable();

            $table->text('fault');

            /*
             * ⚠️ The field that stops an argument.
             *
             * A screen already cracked, a missing back cover, a phone that
             * would not power on. Without it the shop carries every mark the
             * customer notices when they collect.
             */
            $table->text('condition_note')->nullable();

            $table->timestamp('received_at', 6);
            $table->date('promised_for')->nullable();

            // What it was quoted at, which is not what it ends up costing.
            $table->unsignedBigInteger('estimate')->nullable();

            $table->string('status')->default('received');

            // Written when the job is collected and a sale is made from it.
            // Nullable for every job that has not got there yet.
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            $table->text('note')->nullable();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'received_at']);
            $table->index(['received_at', 'id']);
            $table->index('customer_id');
        });

        Schema::create('repair_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('repair_id')->constrained()->cascadeOnDelete();

            // A part off the shelf, or a service — both are products, which is
            // what makes the sale at the end an ordinary one.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // What the customer is charged for it. Held here rather than read
            // from the product at collection, because a price agreed on Monday
            // must not change because somebody edited the product on Tuesday.
            $table->unsignedBigInteger('unit_price');

            $table->timestamps();

            $table->index('repair_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_items');
        Schema::dropIfExists('repairs');
    }
};
