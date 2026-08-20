<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();       // Section 7b: PUR-NNNNN
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // The supplier's own number on their paperwork — separate from document_no.
            $table->string('supplier_invoice_no')->nullable();

            $table->unsignedBigInteger('total_amount')->default(0);

            // Section 6: SIGNED. A supplier may round UP, which is a negative discount.
            // This NEVER touches item prices or batch costs.
            $table->bigInteger('discount_amount')->default(0);

            $table->unsignedBigInteger('grand_total')->default(0);

            // Section 4: derived, never typed. Driven by purchase returns.
            $table->enum('status', ['active', 'partly_returned', 'returned'])->default('active');

            // Section 6b: the rate used on this purchase, stored for reference only.
            // Nothing ever recalculates from it — the stored IQD value is final.
            $table->unsignedInteger('exchange_rate')->nullable();

            $table->date('purchase_date');
            $table->timestamps();
            $table->softDeletes();

            $table->index('purchase_date');
            $table->index('supplier_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
