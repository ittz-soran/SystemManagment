<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 4: the customer/supplier ledger. THIS is the truth;
        // customers.balance / suppliers.balance are caches of the latest balance_after.
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('accountable_type', ['customer', 'supplier']);
            $table->unsignedBigInteger('accountable_id');

            $table->enum('type', [
                'sale', 'purchase', 'payment', 'refund', 'return', 'opening_balance',
            ]);

            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Signed: a sale raises the balance, a payment lowers it.
            $table->bigInteger('amount');

            // Unsigned: balances never go below zero (Section 4, Section 7).
            $table->unsignedBigInteger('balance_after');

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['accountable_type', 'accountable_id', 'created_at'], 'account_tx_ledger_index');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transactions');
    }
};
