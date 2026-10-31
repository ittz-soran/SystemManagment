<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stock check sent to someone — PROJECT_DOC Section 9, Soran, 2026-10-30:
 * *"i want send stock check request to from admin to other users to start"*.
 *
 * Its own migration rather than an edit of 000062, which has been pushed: a
 * shop that already ran 000062 would never run it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_checks', function (Blueprint $table) {
            // Null: anyone's to answer, as before. Users are always restrict —
            // Section 5; a deleted employee is a soft delete.
            $table->foreignId('assigned_to')->nullable()->after('user_id')
                ->constrained('users')->restrictOnDelete();
        });

        Schema::table('stock_check_lines', function (Blueprint $table) {
            // A blind count that did not agree, before the figure was shown.
            $table->unsignedInteger('first_count')->nullable()->after('counted');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            /*
             * An entry addressed to one person: the bell and the phone tell
             * that person, and nobody else. Null for everything else, which is
             * heard by module and permission as before.
             */
            $table->foreignId('recipient_id')->nullable()->after('user_id')
                ->constrained('users')->restrictOnDelete();
            $table->index(['recipient_id', 'id'], 'activity_logs_recipient_index');
        });

        // Here as well as in PermissionSeeder: `shop:update` migrates and does
        // not seed.
        DB::table('permissions')->updateOrInsert(
            ['key' => 'stock_checks.assign'],
            ['group' => 'stock_checks', 'label' => 'Send a stock check to someone', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'stock_checks.assign')->delete();

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_recipient_index');
            $table->dropConstrainedForeignId('recipient_id');
        });

        Schema::table('stock_check_lines', function (Blueprint $table) {
            $table->dropColumn('first_count');
        });

        Schema::table('stock_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
