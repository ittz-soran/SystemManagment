<?php

namespace App\Services;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The bytes leaving the building.
 *
 * ⚠️ **Split out from PushSender on purpose.** Deciding WHO should be told WHAT
 * is the shop's own logic and is worth testing exactly; encrypting a payload
 * and talking to Apple is a library's job and is not. Keeping them apart means
 * the rules can be tested without the crypto — which matters here, because the
 * JWT library warns loudly on a PHP build with neither GMP nor BCMath, and a
 * warning in the middle of a rules test tells nobody anything.
 *
 * It also means a shop can be proved to have decided correctly even where the
 * push service is unreachable.
 */
class PushTransport
{
    /** RFC 8291, which is the only encryption Apple's push service reads. */
    public const ENCODING = 'aes128gcm';

    /**
     * Send everything, and say which subscriptions are gone for good.
     *
     * @param  list<array{endpoint: string, p256dh: string, auth: string, payload: string}>  $messages
     * @return array{failed: int, expired: list<string>}
     */
    public function deliver(array $messages): array
    {
        if ($messages === []) {
            return ['failed' => 0, 'expired' => []];
        }

        /*
         * ⚠️ **The logger is not optional, and leaving it out broke every send.**
         *
         * On a PHP build with neither GMP nor BCMath — which is most shared
         * hosting, and is what Soran's server is — the library announces that
         * the maths will be slow. With no logger to say it to, it says it with
         * `trigger_error(E_USER_NOTICE)`, and Laravel turns every notice into a
         * thrown `ErrorException`. So the constructor threw, `push:send` died
         * on its way out, and not one phone was ever reached. Measured here on
         * a build with both extensions missing: the command exited with a stack
         * trace instead of sending.
         *
         * Thrown away rather than logged because it is advice, not an event,
         * and this runs every minute from cron — it would be the only thing in
         * the log by morning. Real failures are not logged here either: they
         * come back as reports below, which is what the caller acts on.
         */
        $push = new WebPush(
            auth: [
                'VAPID' => [
                    'subject' => config('push.subject'),
                    'publicKey' => config('push.public_key'),
                    'privateKey' => config('push.private_key'),
                ],
            ],
            logger: new NullLogger,
        );

        foreach ($messages as $message) {
            try {
                $push->queueNotification($this->subscriptionFor($message), $message['payload']);
            } catch (Throwable) {
                // A malformed subscription is not worth failing the run for.
            }
        }

        $failed = 0;
        $expired = [];

        try {
            foreach ($push->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }

                $failed++;

                /*
                 * ⚠️ 404 or 410 means the subscription is gone for good — an app
                 * deleted, a browser that rotated its keys. Those are removed
                 * rather than retried forever, which is the only way this table
                 * does not fill up with dead phones.
                 */
                if ($report->isSubscriptionExpired()) {
                    $expired[] = $report->getEndpoint();
                }
            }
        } catch (Throwable) {
            // The push service being unreachable is not a shop problem. The
            // watermark has already moved, so this minute's news is simply not
            // sent — the bell still has all of it.
            $failed = count($messages);
        }

        return ['failed' => $failed, 'expired' => $expired];
    }

    /**
     * One device, described the way the push service expects to hear it.
     *
     * Public so the encoding below can be asserted on the object `deliver()`
     * actually builds, rather than on a constant that could quietly stop being
     * used.
     *
     * @param  array{endpoint: string, p256dh: string, auth: string, payload: string}  $message
     */
    public function subscriptionFor(array $message): Subscription
    {
        return Subscription::create([
            'endpoint' => $message['endpoint'],
            'publicKey' => $message['p256dh'],
            'authToken' => $message['auth'],

            /*
             * ⚠️ **Named, because the library's default is the old draft and an
             * iPhone refuses it.** web-push-php still defaults to `aesgcm`, a
             * 2016 draft; its own docblock says the next major will change.
             * Apple's push service implements the finished standard, RFC 8291,
             * and that is `aes128gcm` — so the default would have encrypted
             * every message in a form the one device Soran actually asked about
             * cannot read. Chrome and Firefox take either.
             */
            'contentEncoding' => self::ENCODING,
        ]);
    }
}
