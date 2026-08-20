<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 4: polymorphic, many payments per document.
        // Amount due = grand_total - SUM(amount WHERE direction = 'in').
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();       // Section 7b: PAY-NNNNN

            $table->enum('payable_type', ['sale', 'purchase', 'sale_return', 'purchase_return']);
            $table->unsignedBigInteger('payable_id');

            // Section 7: the amount is ALWAYS positive and the direction says which way
            // it moved. Never a negative number. Unsigned enforces that.
            $table->unsignedBigInteger('amount');

            // `out` is money leaving the till — a cash refund to a customer, or paying
            // a supplier. Reports read the direction so cash in and out stay legible.
            $table->enum('direction', ['in', 'out']);

            $table->enum('payment_method', ['cash', 'bank', 'transfer'])->default('cash');
            $table->timestamp('paid_at');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payable_type', 'payable_id']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
