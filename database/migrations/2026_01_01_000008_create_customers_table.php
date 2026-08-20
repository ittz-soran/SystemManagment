<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();

            // Section 4: positive = the customer owes the shop.
            // Negative is NOT allowed (Section 7 "Refunds") — unsigned enforces the floor.
            $table->unsignedBigInteger('balance')->default(0);

            // Section 4: the seeded "Cash Customer" row. Cannot be deleted or renamed,
            // and must always be paid in full (no loan).
            $table->boolean('is_system')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
