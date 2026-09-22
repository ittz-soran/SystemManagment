<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time the customer said yes — Soran, 2026-09-21.
 *
 * ⚠️ **A JOB IS AGREED MORE THAN ONCE, AND THE FIRST DESIGN GOT THAT WRONG.**
 * His own case, exactly as he told it:
 *
 *   A PS4 comes in dead. Diagnosis: reinstall the system software, no parts,
 *   quoted 10,000, **haggled down to 8,000**. Agreed at the counter, ticket
 *   printed, customer goes home with it. Mid-job the drive turns out to be
 *   failing — *"now case change before I replace hard drive should call to
 *   customer to describe it again"*. He phones, the customer agrees to a new
 *   drive at 35,000 on top of the 8,000, and the work goes ahead.
 *
 *   *"now customer have old ticket at 8000 but REP is updated and customer are
 *   has been informed by call."*
 *
 * So the record is not one frozen figure and a warning that it has moved. It is
 * a **list of agreements**, each with what was agreed, when, and — the part that
 * matters when somebody disputes it — **how the customer was told**. The paper
 * in their hand may be the old one; what settles it is that a call was logged.
 *
 * It also means the price is **negotiated, not derived**: 10,000 was the quote
 * and 8,000 was the deal. Nothing here reads a price off a product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('repair_id')->constrained()->cascadeOnDelete();

            // What the whole job came to when they agreed to it.
            $table->unsignedBigInteger('total');

            /*
             * ⚠️ How they were told. The first yes is across the counter with
             * the phone in pieces on the bench; the second is a telephone call
             * made before touching anything. That difference is the whole
             * evidence that the customer knew.
             */
            $table->string('channel')->default('counter');

            // What changed and why — "hard drive health dangerous, needs
            // replacing" — in the words used on the call.
            $table->text('note')->nullable();

            $table->timestamp('approved_at', 6);

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['repair_id', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_approvals');
    }
};
