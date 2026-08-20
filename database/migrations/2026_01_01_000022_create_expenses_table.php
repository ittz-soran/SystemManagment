<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->unique();       // Section 7b: EXP-NNNNN
            $table->string('title');

            // restrict: never delete a category that has expenses against it.
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('amount');
            $table->date('expense_date');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('expense_date');
            $table->index('expense_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
