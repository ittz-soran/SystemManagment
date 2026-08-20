<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();       // Section 7b: SRT-NNNNN
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();

            // Section 4: copied from the sale for fast reporting. The sale is the
            // source of truth — set once on creation, never edited independently.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->date('return_date');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('sale_id');
            $table->index('return_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
