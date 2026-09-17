<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phones the shop is allowed to buzz — Soran, 2026-09-17.
 *
 * *"i added to home screen in iphone but not recived notifications, for ex
 * edited an product success, should recived notify to app on iphone"*.
 *
 * The bell polls while somebody is looking at the shop. This is the other half:
 * a message that reaches a phone in a pocket, through Apple's push service,
 * with the app closed.
 *
 * ⚠️ **One row is one DEVICE, not one person.** A shopkeeper with a phone and a
 * counter PC subscribes twice, and each subscription is a separate endpoint
 * that expires on its own. Keyed by the endpoint URL, which is what the browser
 * hands over and what Apple recognises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The push service's own URL for this device. Long: Apple's run to
             * a couple of hundred characters and there is no stated ceiling.
             *
             * ⚠️ Indexed as a prefix rather than made unique, because MySQL
             * cannot index a TEXT column in full and a unique key on 255 bytes
             * of a URL that may share a prefix would refuse a legitimate second
             * device. Duplicates are prevented on the way in instead, by the
             * controller looking the endpoint up.
             */
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();

            // The two keys the browser hands over with the subscription. They
            // encrypt the payload so the push service cannot read it.
            $table->string('p256dh');
            $table->string('auth');

            // So somebody can tell "my phone" from "the counter PC" when they
            // come to turn one off.
            $table->string('device')->nullable();

            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
