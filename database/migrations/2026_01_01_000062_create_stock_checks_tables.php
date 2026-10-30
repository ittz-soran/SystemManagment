<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock check — PROJECT_DOC Section 9, Soran, 2026-10-30.
 *
 * *"stock check manually, for ex in Stock Check show PD-17L-UK In stock,
 * batches, movments... and Show Confirm (mean all correct not need movment),
 * Skip (Show another product), Wrong (mean need correction by ADJ or
 * return)"*.
 *
 * A round is one pass over the shelves; a line is one product in it and the
 * answer given for it. ⚠️ The line keeps the figure the product was checked
 * AGAINST, read when the answer was saved — not when the round began, because
 * a round runs for days and the till keeps selling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_checks', function (Blueprint $table) {
            $table->id();

            // Section 7b: CHK-00001, like every document.
            $table->string('document_no')->unique();

            // all · category · room
            $table->string('scope', 16);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('stock_rooms')->nullOnDelete();

            // Products the system said were at zero when the round began.
            $table->boolean('with_empty')->default(false);

            // The system's figure hidden until the count is typed.
            $table->boolean('blind')->default(false);

            $table->timestamp('started_at', 6);
            $table->timestamp('finished_at', 6)->nullable();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['finished_at', 'id']);
        });

        Schema::create('stock_check_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // The order the round walks the shelves in.
            $table->unsignedInteger('position');

            // pending · confirmed · wrong · skipped
            $table->string('status', 16)->default('pending');

            // What the system said when the answer was saved, and the count.
            $table->integer('expected')->nullable();
            $table->integer('counted')->nullable();

            // A wrong answer: put right by an ADJ, or later, or by something
            // else the shop marks it fixed with.
            $table->foreignId('stock_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fix', 16)->nullable(); // adjusted · later · fixed
            $table->text('note')->nullable();
            $table->timestamp('fixed_at', 6)->nullable();

            $table->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('checked_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['stock_check_id', 'product_id']);
            $table->index(['stock_check_id', 'status', 'position']);
            // "Last checked" on a product, and the not-checked filter.
            $table->index(['product_id', 'checked_at']);
            $table->index(['status', 'fix']);
        });

        /*
         * ⚠️ The permissions, here as well as in PermissionSeeder. A shop that
         * is already running gets new code by `shop:update`, which migrates and
         * does not seed — so without this, nobody but an admin could ever be
         * given the stock check.
         */
        foreach ([
            'stock_checks.view' => 'See stock checks and what is still to fix',
            'stock_checks.count' => 'Start a stock check and count in it',
        ] as $key => $label) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['group' => 'stock_checks', 'label' => $label, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', ['stock_checks.view', 'stock_checks.count'])->delete();
        Schema::dropIfExists('stock_check_lines');
        Schema::dropIfExists('stock_checks');
    }
};
