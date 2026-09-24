<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taking one thing apart, and putting several together — Soran, 2026-09-24.
 *
 * *"I purchased second hand ps5 slim digital have box and 2 controller -> I
 * purchased all at 750,000 -> today I want sale it but customer say need 1
 * controller!! ... this cases repeat daily in gaming pc build"*.
 *
 * ⚠️ **One document, both directions, because they are the same arithmetic.**
 * Taking apart consumes one thing and creates several; putting together
 * consumes several and creates one. Either way the money does not move: what
 * comes out is worth exactly what went in, and the shop has neither earned nor
 * lost anything by opening a box.
 *
 * ⚠️ **Why not two stock adjustments.** An outgoing adjustment is a WRITE-OFF
 * and lands in the profit report as one. A shop that took a 750,000 console
 * apart would read a 750,000 loss on the day it did so, and a matching pile of
 * stock appearing from nowhere. The same reason the faulty swap could not be an
 * adjustment: the adjustment screen is for the shelf being wrong, and here the
 * shelf was right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assemblies', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();

            /*
             * Which way round. `apart` takes one thing to pieces; `together`
             * makes one thing out of several. Stored rather than inferred from
             * the rows, because a document must say what it is even when its
             * lines are read separately.
             */
            $table->enum('direction', ['apart', 'together']);

            /*
             * What was consumed, and therefore what was created — one figure,
             * because they are the same figure. Frozen here: the batches behind
             * it will move on, and the document must still say what it was
             * worth on the day.
             */
            $table->unsignedBigInteger('total_cost');

            /*
             * ⚠️ DATETIME, not TIMESTAMP. MySQL gives the first NOT NULL
             * TIMESTAMP column with no default `ON UPDATE CURRENT_TIMESTAMP`
             * and then rewrites it on every update to the row — which is what
             * silently broke the FIFO order in Soran's shop on 2026-09-24. See
             * the migration that changed all eight of them back.
             */
            $table->dateTime('assembled_at', 6);
            $table->text('note')->nullable();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assembled_at', 'id']);
        });

        Schema::create('assembly_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            /*
             * Which side of the document this line is on. `source` went in,
             * `result` came out — and which of them is the single line depends
             * on the direction.
             */
            $table->enum('role', ['source', 'result']);

            $table->unsignedInteger('quantity');

            /*
             * What each unit of this line was worth. For a source it is what
             * FIFO actually charged; for a result it is what the shop decided
             * that piece is worth out of the whole. ⚠️ The sum of the results
             * equals the sum of the sources, exactly, or the document is
             * refused — see AssemblyService.
             */
            $table->unsignedBigInteger('unit_cost');

            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['assembly_id', 'role']);
            $table->index('product_id');
        });

        /*
         * ⚠️ The audit table learns one more word, for the reason the transfer
         * and the swap migrations both wrote down before it: `reference_type`
         * exists to say truthfully what moved a unit, and taking a console
         * apart is a new answer. Writing it as an "adjustment" would avoid this
         * line and would be a lie in the one table that must not hold any.
         */
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('reference_type', [
                'purchase', 'sale', 'sale_return', 'purchase_return',
                'adjustment', 'transfer', 'swap', 'assembly',
            ])->change();
        });

        // And a layer can now be born of one: the controller that came out of
        // the box is stock, and its source is the document that opened it.
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->enum('source_type', ['purchase', 'adjustment', 'transfer', 'assembly'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->enum('source_type', ['purchase', 'adjustment', 'transfer'])->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('reference_type', [
                'purchase', 'sale', 'sale_return', 'purchase_return',
                'adjustment', 'transfer', 'swap',
            ])->change();
        });

        Schema::dropIfExists('assembly_items');
        Schema::dropIfExists('assemblies');
    }
};
