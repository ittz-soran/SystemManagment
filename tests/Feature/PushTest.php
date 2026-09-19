<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushSender;
use App\Services\PushTransport;
use App\Support\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reaching a phone with the app closed — Soran, 2026-09-17.
 *
 * *"i added to home screen in iphone but not recived notifications, for ex
 * edited an product success, should recived notify to app on iphone are added
 * to home screen"*.
 *
 * ⚠️ **The root cause was not the notifications, it was the worker.** `sw.js`
 * had been served since Add to Home Screen was built and NOTHING EVER CALLED
 * `navigator.serviceWorker.register()`. A worker that is served and never
 * registered does not exist to the browser, and `PushManager` lives on the
 * registration — so no push could ever have arrived, whatever else was built.
 * The first test here is that one.
 */
class PushTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    // =====================================================================
    // The worker
    // =====================================================================

    /** ⚠️ The bug. A served worker that nothing registers does nothing at all. */
    public function test_something_actually_registers_the_service_worker(): void
    {
        $js = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString(
            'navigator.serviceWorker.register(',
            $js,
            'Nothing registers the service worker, so no notification can ever reach a phone.'
        );

        // And the page has to tell it where the worker is.
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('name="service-worker"', escape: false);
    }

    /** The worker knows what to do when a message arrives, and when it is tapped. */
    public function test_the_worker_shows_what_arrives_and_opens_the_shop_when_tapped(): void
    {
        $worker = $this->actingAs($this->admin)->get(route('install.worker'))->assertOk()->getContent();

        $this->assertStringContainsString("addEventListener('push'", $worker);
        $this->assertStringContainsString('showNotification', $worker);
        $this->assertStringContainsString("addEventListener('notificationclick'", $worker);

        // ⚠️ Focus a window the shop already has open rather than opening a
        // second one — otherwise a half-typed sale is left in a window nobody
        // can find.
        $this->assertStringContainsString('matchAll', $worker);
    }

    // =====================================================================
    // Devices
    // =====================================================================

    public function test_a_device_can_ask_to_be_notified(): void
    {
        $this->actingAs($this->admin)->postJson(route('notifications.subscribe'), [
            'endpoint' => 'https://web.push.apple.com/abc123',
            'keys' => ['p256dh' => 'a-public-key', 'auth' => 'an-auth-token'],
        ])->assertOk();

        $this->assertSame(1, PushSubscription::where('user_id', $this->admin->id)->count());
    }

    /**
     * ⚠️ The same phone twice is one row.
     *
     * Browsers re-subscribe freely — after an update, after clearing data — and
     * a table of duplicates would mean the same buzz three times.
     */
    public function test_the_same_device_subscribing_twice_is_one_row(): void
    {
        foreach ([1, 2, 3] as $ignored) {
            $this->actingAs($this->admin)->postJson(route('notifications.subscribe'), [
                'endpoint' => 'https://web.push.apple.com/abc123',
                'keys' => ['p256dh' => 'a-public-key', 'auth' => 'an-auth-token'],
            ])->assertOk();
        }

        $this->assertSame(1, PushSubscription::count());
    }

    /** And a person may only switch off their own. */
    public function test_a_device_belonging_to_somebody_else_cannot_be_switched_off(): void
    {
        $other = User::create([
            'name' => 'Hawkar', 'email' => 'h@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER, 'is_active' => true,
        ]);

        PushSubscription::create([
            'user_id' => $other->id,
            'endpoint' => 'https://web.push.apple.com/theirs',
            'endpoint_hash' => PushSubscription::hashFor('https://web.push.apple.com/theirs'),
            'p256dh' => 'k', 'auth' => 'a',
        ]);

        $this->actingAs($this->admin)->deleteJson(route('notifications.unsubscribe'), [
            'endpoint' => 'https://web.push.apple.com/theirs',
        ])->assertOk();

        $this->assertSame(1, PushSubscription::count(), 'One person unsubscribed another person’s phone.');
    }

    // =====================================================================
    // What gets sent
    // =====================================================================

    /** A shop with no keys sends nothing, and that is not an error. */
    public function test_a_shop_with_no_keys_does_nothing_rather_than_failing(): void
    {
        config(['push.public_key' => null, 'push.private_key' => null]);

        $this->assertFalse(app(PushSender::class)->configured());

        $this->artisan('push:send')->assertSuccessful();
    }

    /**
     * ⚠️ The watermark moves BEFORE anything is sent.
     *
     * A run that died halfway would otherwise send its whole batch again on the
     * next minute, and a shopkeeper would get the same buzz over and over.
     */
    public function test_nothing_is_ever_sent_twice(): void
    {
        $this->withKeys();
        $this->device();

        $seller = $this->seller();
        $this->entry($seller, 'update', 'products', 'Updated Product Pilot Pen');

        $first = app(PushSender::class)->run();
        $second = app(PushSender::class)->run();

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent'], 'The same activity was pushed twice.');
    }

    /** Nothing new means nothing sent. */
    public function test_a_quiet_shop_sends_nothing(): void
    {
        $this->withKeys();
        $this->device();

        app(PushSender::class)->run();

        $this->assertSame(0, app(PushSender::class)->run()['sent']);
    }

    /**
     * ⚠️ A phone can never be told something its owner could not open.
     *
     * The same rule as the bell, and the reason this reads `activity_logs`
     * through NotificationFeed rather than keeping its own list.
     */
    public function test_a_phone_is_never_told_what_its_owner_may_not_see(): void
    {
        $this->withKeys();

        $seller = $this->seller();
        $device = $this->device($seller);

        // Something the counter assistant holds no permission for.
        $this->entry($this->admin, 'delete', 'purchases', 'Deleted Purchase PUR-00001');

        $this->assertSame(0, app(PushSender::class)->run()['sent']);
    }

    /** Somebody's own doing is not news to their own phone. */
    public function test_a_phone_is_not_buzzed_by_its_owners_own_work(): void
    {
        $this->withKeys();
        $this->device();

        $this->entry($this->admin, 'update', 'products', 'Updated Product Pilot Pen');

        $this->assertSame(0, app(PushSender::class)->run()['sent']);
    }

    /**
     * ⚠️ The day's sales do not buzz a pocket.
     *
     * Routine is not in anybody's phone tiers, and a hundred buzzes a day is a
     * phone somebody switches off — taking the alert that mattered with it.
     */
    public function test_the_days_sales_do_not_reach_the_phone(): void
    {
        $this->withKeys();
        $this->device();

        $seller = $this->seller();
        $this->entry($seller, 'create', 'sales', 'Created Sale INV-00009');

        $this->assertSame(0, app(PushSender::class)->run()['sent']);
    }

    /**
     * ⚠️ One buzz for the minute, not one per thing that happened.
     *
     * Five things in a minute is one glance at a lock screen, not five buzzes
     * and a phone somebody switches off — taking the alert that mattered with
     * it.
     */
    public function test_several_things_at_once_are_one_message(): void
    {
        $this->withKeys();
        $this->device();

        $seller = $this->seller();
        $this->entry($seller, 'create', 'products', 'Created Product One');
        $this->entry($seller, 'create', 'products', 'Created Product Two');
        $this->entry($seller, 'create', 'products', 'Created Product Three');

        $this->assertSame(1, app(PushSender::class)->run()['sent']);

        $sent = $this->sent();
        $this->assertCount(1, $sent, 'One device received more than one message for one minute.');

        $payload = json_decode($sent[0]['payload'], true);

        // The newest, and a count of the rest.
        $this->assertStringContainsString('Created Product Three', $payload['body']);
        $this->assertStringContainsString('2', $payload['body']);

        // The shop's own name, so a lock screen says whose shop this is.
        $this->assertSame(setting('shop_name', config('app.name')), $payload['title']);

        // Tapping it lands on the list rather than nowhere.
        $this->assertSame(route('notifications.index'), $payload['url']);

        // ⚠️ One tag, so the next message replaces this one rather than
        // stacking eleven of them down a lock screen.
        $this->assertSame('shop-news', $payload['tag']);
    }

    /** The phone has its own switch, separate from the bell's. */
    public function test_the_phone_can_be_turned_down_without_touching_the_bell(): void
    {
        $this->admin->forceFill(['notify_tiers' => 'alert,news', 'push_tiers' => 'alert'])->save();

        $fresh = $this->admin->fresh();

        $this->assertContains(Notifications::NEWS, Notifications::tiersFor($fresh));
        $this->assertNotContains(Notifications::NEWS, Notifications::pushTiersFor($fresh));
    }

    /** Alerts reach the phone whatever else is switched off. */
    public function test_alerts_always_reach_the_phone(): void
    {
        $this->admin->forceFill(['push_tiers' => ''])->save();

        $this->assertContains(Notifications::ALERT, Notifications::pushTiersFor($this->admin->fresh()));
    }

    /** A shop that has been offline for a day does not wake up and send a thousand. */
    public function test_a_long_backlog_is_skipped_rather_than_sent(): void
    {
        $this->withKeys();
        $this->device();

        $seller = $this->seller();

        for ($i = 0; $i < PushSender::BATCH + 30; $i++) {
            $this->entry($seller, 'create', 'products', 'Created Product '.$i);
        }

        $tally = app(PushSender::class)->run();

        $this->assertGreaterThan(0, $tally['skipped'], 'A day of backlog would have been sent as a flood.');
    }

    // =====================================================================
    // The wire
    // =====================================================================

    /**
     * ⚠️ **The second bug, and it killed every send on Soran's server.**
     *
     * On a PHP build with neither GMP nor BCMath — most shared hosting, and his
     * — the JWT library announces that the maths will be slow by calling
     * `trigger_error(E_USER_NOTICE)`, and Laravel turns every notice into a
     * thrown `ErrorException`. `WebPush` was constructed outside any try, so
     * the whole command died on its way out and not one phone was reached. It
     * never showed up because every other test here swaps the transport out.
     *
     * The endpoint is a closed port, so this asks for a real construction and a
     * real refusal without waiting on a network.
     */
    public function test_sending_survives_a_php_build_with_no_maths_extensions(): void
    {
        config([
            'push.public_key' => 'BKagOnzmBpAD1tzWQZ1Lm7t5pTzT0Vg0qV4iNiMSRM6bOoqNzlBEQfBLKJPNSPvdqK3fXmPQrrRoU1sZSfBOsQY',
            'push.private_key' => 'gJqPMUT5NKUZ5sfBhGb9JCXnUR6oXNjKdgAlFRNMPvI',
            'push.subject' => 'mailto:shop@example.com',
        ]);

        $report = (new PushTransport)->deliver([[
            'endpoint' => 'http://127.0.0.1:1/closed',
            'p256dh' => 'BKagOnzmBpAD1tzWQZ1Lm7t5pTzT0Vg0qV4iNiMSRM6bOoqNzlBEQfBLKJPNSPvdqK3fXmPQrrRoU1sZSfBOsQY',
            'auth' => 'c2VjcmV0LWF1dGgtdG9rZW4',
            'payload' => '{"title":"x","body":"y"}',
        ]]);

        // Unreachable, so it fails — but it fails as a report, not as a crash.
        $this->assertSame(1, $report['failed']);
        $this->assertSame([], $report['expired']);
    }

    /**
     * ⚠️ **The library's default encryption is one an iPhone will not read.**
     *
     * web-push-php still defaults to `aesgcm`, a 2016 draft. Apple's push
     * service implements the finished standard, RFC 8291, which is
     * `aes128gcm`. Left at the default, every message Soran's phone was sent
     * would have been rejected by Apple before it ever rang.
     *
     * Asserted on the object `deliver()` builds, so removing the option fails
     * here rather than quietly going back to the draft.
     */
    public function test_messages_are_encrypted_the_way_apple_reads_them(): void
    {
        $subscription = (new PushTransport)->subscriptionFor([
            'endpoint' => 'https://web.push.apple.com/one',
            'p256dh' => 'BKagOnzmBpAD1tzWQZ1Lm7t5pTzT0Vg0qV4iNiMSRM6bOoqNzlBEQfBLKJPNSPvdqK3fXmPQrrRoU1sZSfBOsQY',
            'auth' => 'c2VjcmV0LWF1dGgtdG9rZW4',
            'payload' => '{"title":"x","body":"y"}',
        ]);

        $this->assertSame('aes128gcm', $subscription->getContentEncoding());
    }

    /**
     * Reaching the push service can hang forever, and a button that hangs is
     * the same "nothing happened" this screen was built to end. Measured in a
     * browser that could not reach one: twenty seconds and still waiting.
     */
    public function test_a_phone_that_cannot_reach_the_push_service_is_told_so(): void
    {
        $js = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('Promise.race', $js, 'Nothing bounds pushManager.subscribe(), so the button can hang silently.');
        $this->assertStringContainsString('box.dataset.slow', $js);

        $this->actingAs($this->admin)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('data-slow=', escape: false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * A transport that records instead of encrypting.
     *
     * ⚠️ The rules are the shop's and are worth testing exactly; talking to
     * Apple is a library's job and is not. Swapping it here also keeps the JWT
     * library's "install GMP or BCMath" warning out of tests about who gets
     * told what, where it tells nobody anything.
     *
     * @return list<array<string, string>>
     */
    private function sent(): array
    {
        return self::$posted;
    }

    /** @var list<array<string, string>> */
    private static array $posted = [];

    private function fakeTransport(): void
    {
        self::$posted = [];

        $this->app->bind(PushTransport::class, fn () => new class extends PushTransport
        {
            public function deliver(array $messages): array
            {
                PushTest::record($messages);

                return ['failed' => 0, 'expired' => []];
            }
        });
    }

    /** @param  list<array<string, string>>  $messages */
    public static function record(array $messages): void
    {
        self::$posted = $messages;
    }

    private function withKeys(): void
    {
        $this->fakeTransport();

        config([
            'push.public_key' => 'BKagOnzmBpAD1tzWQZ1Lm7t5pTzT0Vg0qV4iNiMSRM6bOoqNzlBEQfBLKJPNSPvdqK3fXmPQrrRoU1sZSfBOsQY',
            'push.private_key' => 'gJqPMUT5NKUZ5sfBhGb9JCXnUR6oXNjKdgAlFRNMPvI',
            'push.subject' => 'mailto:shop@example.com',
        ]);
    }

    private function device(?User $user = null): PushSubscription
    {
        $user ??= $this->admin;

        return PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://web.push.apple.com/'.$user->id,
            'endpoint_hash' => PushSubscription::hashFor('https://web.push.apple.com/'.$user->id),
            'p256dh' => 'BKagOnzmBpAD1tzWQZ1Lm7t5pTzT0Vg0qV4iNiMSRM6bOoqNzlBEQfBLKJPNSPvdqK3fXmPQrrRoU1sZSfBOsQY',
            'auth' => 'c2VjcmV0LWF1dGgtdG9rZW4',
        ]);
    }

    /** A counter assistant: sales and products, nothing else. */
    private function seller(): User
    {
        $seller = User::create([
            'name' => 'Hawkar', 'email' => 'hawkar@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER, 'is_active' => true,
        ]);

        $seller->permissions()->sync(
            Permission::whereIn('key', ['auth.login', 'sales.create', 'sales.view', 'products.view'])->pluck('id')
        );

        return $seller->fresh()->load('permissions');
    }

    private function entry(User $by, string $action, string $module, string $description): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $by->id,
            'action' => $action,
            'module' => $module,
            'tier' => Notifications::tierFor($action, $module),
            'description' => $description,
        ]);
    }
}
