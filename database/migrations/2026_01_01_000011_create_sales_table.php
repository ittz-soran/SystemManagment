<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();       // Section 7b: INV-NNNNN

            // Section 4: required FK. Walk-ins point at the seeded Cash Customer.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Section 4: sales have NO discount field. Price is controlled per line.
            $table->unsignedBigInteger('total_amount')->default(0);

            // Section 4: derived, never typed. Recomputed from the lines after any return.
            $table->enum('status', ['active', 'partly_returned', 'returned'])->default('active');

            $table->date('sale_date');
            $table->timestamps();
            $table->softDeletes();

            $table->index('sale_date');
            $table->index('customer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
