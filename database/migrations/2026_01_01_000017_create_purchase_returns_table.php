<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();       // Section 7b: PRT-NNNNN
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();

            // Copied from the purchase — same rule as sale_returns.customer_id.
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->date('return_date');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('purchase_id');
            $table->index('return_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
