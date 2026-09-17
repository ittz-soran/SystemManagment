<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Support\Notifications;
use Illuminate\Support\Collection;

/**
 * Buzzing a phone that is not looking at the shop — Soran, 2026-09-17.
 *
 * *"i added to home screen in iphone but not recived notifications, for ex
 * edited an product success, should recived notify to app on iphone"*.
 *
 * ⚠️ **Nothing new is recorded to make this work.** It reads the same
 * `activity_logs` the bell reads and applies the same visibility rules —
 * `NotificationFeed::visible()` — so a phone can never be told something its
 * owner could not open on screen. A second record of "what to notify about"
 * would be a second thing that can disagree with the first.
 *
 * ⚠️ **Sent from cron, not from the request that caused it.** This shop runs
 * `QUEUE_CONNECTION=sync` and has no queue worker; sending inline would make
 * saving a product wait on Apple's servers, over somebody's shop wifi, before
 * the page came back. The cron that already runs `schedule:run` every minute
 * drains this instead, so a notification arrives within about a minute and a
 * slow push service can never hold up a sale.
 */
class PushSender
{
    public function __construct(private readonly PushTransport $transport) {}

    /** Where the last run got to, so nothing is sent twice. */
    public const WATERMARK = 'push_last_log_id';

    /**
     * How many entries one run will look at.
     *
     * A shop that has been offline for a day must not wake up and send four
     * thousand pushes. Past this, the watermark jumps forward and the backlog
     * is simply skipped — the bell still has all of it, which is where history
     * belongs.
     */
    public const BATCH = 200;

    /** @return array{sent: int, devices: int, failed: int, skipped: int} */
    public function run(): array
    {
        $tally = ['sent' => 0, 'devices' => 0, 'failed' => 0, 'skipped' => 0];

        if (! $this->configured()) {
            return $tally;
        }

        $from = (int) setting(self::WATERMARK, 0);
        $latest = (int) ActivityLog::max('id');

        if ($latest <= $from) {
            return $tally;
        }

        // ⚠️ Moved FIRST, before anything is sent. A run that crashed halfway
        // would otherwise send its whole batch again on the next minute, and a
        // shopkeeper would get the same buzz over and over.
        Setting::put(self::WATERMARK, (string) $latest);

        if ($latest - $from > self::BATCH) {
            $tally['skipped'] = $latest - $from - self::BATCH;
            $from = $latest - self::BATCH;
        }

        $subscriptions = PushSubscription::with('user')->get();

        if ($subscriptions->isEmpty()) {
            return $tally;
        }

        $tally['devices'] = $subscriptions->count();

        $messages = [];

        foreach ($subscriptions->groupBy('user_id') as $devices) {
            $user = $devices->first()->user;

            if (! $user) {
                continue;
            }

            $entries = $this->forUser($user, $from, $latest);

            if ($entries->isEmpty()) {
                continue;
            }

            $payload = $this->payload($entries);

            foreach ($devices as $device) {
                $messages[] = [
                    'endpoint' => $device->endpoint,
                    'p256dh' => $device->p256dh,
                    'auth' => $device->auth,
                    'payload' => $payload,
                ];

                $device->forceFill(['last_sent_at' => now()])->save();

                $tally['sent']++;
            }
        }

        $result = $this->transport->deliver($messages);

        $tally['failed'] = $result['failed'];

        foreach ($result['expired'] as $endpoint) {
            PushSubscription::where('endpoint_hash', PushSubscription::hashFor($endpoint))->delete();
        }

        return $tally;
    }

    /** Whether a shop has been given keys to send with. */
    public function configured(): bool
    {
        return config('push.public_key') !== null && config('push.private_key') !== null;
    }

    /**
     * What this person may be told about, out of the entries in this window.
     *
     * ⚠️ The bell's own rules, not a second set: their permissions, their own
     * actions excluded, personal entries only to their owner. The one thing
     * added is the PHONE's tier setting, which is deliberately separate from
     * the bell's.
     *
     * @return Collection<int, ActivityLog>
     */
    private function forUser(User $user, int $from, int $latest): Collection
    {
        return app(NotificationFeed::class)
            ->visible($user)
            ->whereIn('tier', Notifications::pushTiersFor($user))
            ->where('id', '>', $from)
            ->where('id', '<=', $latest)
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * What one message says.
     *
     * ⚠️ The NEWEST entry, with a count of the rest — never one push per entry.
     * Five things happening in a minute is one buzz and a glance, not five
     * buzzes and a phone somebody turns off.
     *
     * @param  Collection<int, ActivityLog>  $entries
     */
    private function payload(Collection $entries): string
    {
        $newest = $entries->first();
        $more = $entries->count() - 1;

        $body = $more > 0
            ? __(':what · and :count more', ['what' => $newest->description, 'count' => $more])
            : (string) $newest->description;

        return (string) json_encode([
            'title' => (string) setting('shop_name', config('app.name')),
            'body' => $body,

            /*
             * Where tapping it lands: the notifications page rather than the
             * entry, because the entry may be one of several and this is the
             * screen that shows them all.
             */
            'url' => route('notifications.index'),

            // One tag, so a second message REPLACES the first on the lock
            // screen rather than stacking.
            'tag' => 'shop-news',
        ], JSON_UNESCAPED_UNICODE);
    }
}
