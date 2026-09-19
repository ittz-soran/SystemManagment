<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bell (Section 9b), and the three columns it needs.
 *
 * ⚠️ **There is no notifications table, and that is the design.** The obvious
 * build is a row per person per event, written by every service. That gives the
 * shop two records of the same thing, written from two places, which can and
 * will disagree — an entry that logged but did not notify, a notification for a
 * purchase that was rolled back.
 *
 * `activity_logs` already records every create, update, delete and login. So a
 * notification is a READING POSITION in that log, not a copy of it: your unread
 * list is the rows past your mark. One record of what happened, and the bell is
 * a reader standing at a point in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * ⚠️ The id of the last entry this person has read — NOT a
             * timestamp.
             *
             * A timestamp was the first build and it was wrong. `created_at` on
             * this table is whole seconds, so "everything up to now is read"
             * silently swallowed anything written in the same second the bell
             * was opened. Losing a notification is survivable for a price
             * change and is not survivable for the one that says somebody else
             * signed into your account.
             *
             * An id is exact, monotonic, and free — it is the column the log is
             * already ordered by.
             */
            $table->unsignedBigInteger('notifications_seen_id')->nullable()->after('remember_token');

            // Which tiers reach this person. A short list like the units
            // setting, rather than a column per tier, so a fourth tier is not a
            // migration. Routine is deliberately not in the default: a hundred
            // sales a day is a bell nobody reads.
            $table->string('notify_tiers', 64)->default('alert,news')->after('notifications_seen_id');
        });

        /*
         * ⚠️ Everybody already in the shop starts read.
         *
         * Soran's shop has been running since the spring and has a log to show
         * for it. Without this line the morning he installs the bell, it says
         * four thousand — and a badge that says four thousand is a badge nobody
         * ever clears, which is the same as having built nothing.
         *
         * New accounts get the same treatment as they are created; see
         * User::booted().
         */
        DB::table('users')->update([
            'notifications_seen_id' => DB::table('activity_logs')->max('id') ?? 0,
        ]);

        Schema::table('activity_logs', function (Blueprint $table) {
            /*
             * Written when the row is written, not worked out on every poll.
             *
             * The feed runs every three-quarters of a minute for every
             * signed-in person, and classifying a thousand rows in PHP each
             * time to find the four that matter is the kind of query that is
             * fine on Soran's shop and miserable on a busy one. Stored and
             * indexed, it is a range scan.
             */
            $table->string('tier', 16)->default('routine')->after('module');
            $table->index(['tier', 'id'], 'activity_logs_bell_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notifications_seen_id', 'notify_tiers']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_bell_index');
            $table->dropColumn('tier');
        });
    }
};
