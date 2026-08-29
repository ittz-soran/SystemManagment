<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One more thing that can happen to a record: it can stop existing.
 *
 * Every other action in this list can be undone from the screen it was done on
 * — a delete is a soft delete, and `restore` is right there beside it. Deleting
 * a product permanently is the row leaving the table, and recording it as an
 * ordinary `delete` would make the log say the one thing it must never say:
 * that something reversible happened.
 */
return new class extends Migration
{
    private const ACTIONS = ['login', 'logout', 'create', 'update', 'delete', 'restore', 'purge'];

    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->enum('action', self::ACTIONS)->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->enum('action', array_diff(self::ACTIONS, ['purge']))->change();
        });
    }
};
