<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A way back in that does not go through the post.
 *
 * MAIL_MAILER is `log` on every install of this system, and on the shared
 * hosting these shops run on it will stay that way — so Laravel's own
 * "forgotten password" link has never left the building. Which was survivable
 * while the only user was the person who built it, and stops being survivable
 * the moment the system is sold: an owner who forgets their password has
 * nobody to ask, and their whole shop's records are behind it.
 *
 * So the second factor is a phone that already holds the answer. Nothing has to
 * be delivered.
 *
 * The secret is encrypted at rest, because a database dump that hands over both
 * the password hashes and the thing that resets them has handed over the shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');

            // Eight one-time codes, for the phone that is lost, wiped or in a
            // pocket in another city. Without these an authenticator is a
            // second way to be locked out rather than a way back in.
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');

            // Set only once a code has actually been typed back correctly. A
            // secret that was generated but never proved is worse than none:
            // it looks like a way in, on a screen, and is not one.
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
