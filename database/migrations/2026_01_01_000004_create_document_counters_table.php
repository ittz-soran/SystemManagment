<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 7b: one row per prefix, each sequence independent.
        // Incremented inside the document's own transaction with SELECT ... FOR UPDATE.
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10)->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};
