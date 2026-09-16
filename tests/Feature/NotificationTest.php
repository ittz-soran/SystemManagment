<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use App\Services\NotificationFeed;
use App\Support\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bell.
 *
 * **Soran, 2026-09-15:** *"notification system to user get last changes or live
 * changes like added new product to products or another one login in your
 * account…. With more notify alerts to user are need to see."*
 *
 * Most of what follows is about what the bell must NOT say. That is deliberate:
 * a notification is one of the few places in this shop where one person's work
 * is drawn on another person's screen, and every rule here is a way that could
 * go wrong — a salesperson being told a purchase price the shop hid from them,
 * a bell that announces its reader's own sale back to him, a new employee
 * signing in to four thousand unread entries from before they worked here.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private NotificationFeed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->feed = app(NotificationFeed::class);
    }

    /**
     * ⚠️ The one that matters most.
     *
     * A salesperson with no purchases permission must never be told "purchase
     * price changed to 38,500". The setting that hides cost exists precisely to
     * keep that figure off their screen, and a bell is a screen.
     */
    public function test_it_never_mentions_a_module_the_reader_cannot_open(): void
    {
        $seller = $this->seller();

        /*
         * ⚠️ A DELETED purchase, not an edited one.
         *
         * An edit to a purchase is routine, and routine is off by default — so
         * a test written with one passes whether or not the permission filter
         * exists, because the tier filter quietly does the work. That is a test
         * that agrees with the bug it claims to catch. A deleted purchase is an
         * alert, alerts cannot be switched off, and nothing but the permission
         * filter stands between it and this reader.
         */
        $this->entry($this->admin, 'delete', 'purchases', 'Deleted Purchase PUR-00001');
        $this->entry($this->admin, 'create', 'products', 'Created Product Pilot Pen');

        $this->assertSame(
            Notifications::ALERT,
            Notifications::tierFor('delete', 'purchases'),
            'This test only means anything while a deleted purchase is an alert.'
        );

        $said = $this->feed->visible($seller)->pluck('module')->all();

        $this->assertContains('products', $said, 'A seller who may open products was told nothing about them.');
        $this->assertNotContains(
            'purchases',
            $said,
            'The bell told a seller about purchases, which they hold no permission to open. '
            .'That is the purchase price reaching a screen the shop hid it from.'
        );
    }

    /** Somebody's own work is not news to them. */
    public function test_it_does_not_report_a_reader_to_themselves(): void
    {
        $seller = $this->seller();

        $this->entry($seller, 'create', 'products', 'Created Product Pilot Pen');

        $this->assertSame(
            0,
            $this->feed->visible($seller)->count(),
            'The bell announced to a shopkeeper the thing he had just done himself.'
        );
    }

    /**
     * A sign-in belongs to the person it is about.
     *
     * Showing "Hawkar signed in" to everybody else would turn the bell into a
     * staff tracker, which is not what was asked for and not a thing to build
     * by accident.
     */
    public function test_one_persons_sign_in_is_never_shown_to_another(): void
    {
        $seller = $this->seller();

        $this->entry($seller, 'login', 'auth', 'Logged in', tier: Notifications::ALERT);

        $this->assertSame(
            0,
            $this->feed->visible($this->admin)->count(),
            'The admin was shown somebody else’s sign-in.'
        );

        $this->assertSame(
            1,
            $this->feed->visible($seller)->count(),
            'The person who signed in was not shown their own sign-in.'
        );
    }

    /**
     * ⚠️ Signing in is history. Signing in from somewhere new is an alert.
     *
     * The obvious build rings on every sign-in, and a bell that rings twice a
     * day for a shopkeeper arriving at his own counter is a bell whose red dot
     * means nothing by Thursday — so the one sign-in that was not him is the
     * one nobody looks at.
     */
    public function test_a_familiar_address_is_quiet_and_a_new_one_is_an_alert(): void
    {
        $this->entry($this->admin, 'login', 'auth', 'Logged in', ip: '192.168.1.20');

        $this->assertSame(
            Notifications::ROUTINE,
            Notifications::signInTier($this->admin, '192.168.1.20'),
            'Signing in from the counter this account always uses was treated as an alert.'
        );

        $this->assertSame(
            Notifications::ALERT,
            Notifications::signInTier($this->admin, '203.0.113.7'),
            'Signing in from an address this account has never used was treated as routine. '
            .'That is exactly the event Soran asked to be told about.'
        );
    }

    /** Nothing is familiar on the first day, so nobody is warned about themselves. */
    public function test_the_first_sign_in_an_account_ever_makes_is_not_an_alert(): void
    {
        $this->assertSame(
            Notifications::ROUTINE,
            Notifications::signInTier($this->seller(), '203.0.113.7'),
            'A fresh account was greeted with a security warning about its own first sign-in.'
        );
    }

    /** An address the server could not read is the safe direction to be loud. */
    public function test_an_unreadable_address_is_an_alert(): void
    {
        $this->entry($this->admin, 'login', 'auth', 'Logged in', ip: '192.168.1.20');

        $this->assertSame(Notifications::ALERT, Notifications::signInTier($this->admin, null));
    }

    /**
     * ⚠️ Turning everything off does not turn off an alert.
     *
     * Somebody who has silenced the bell must still be told that their account
     * was signed into from an address they do not use, and that the invoices
     * were deleted. A preference is about noise, not about being kept in the
     * dark.
     */
    public function test_alerts_reach_somebody_who_has_turned_everything_off(): void
    {
        $this->admin->forceFill(['notify_tiers' => ''])->save();

        $this->assertContains(Notifications::ALERT, Notifications::tiersFor($this->admin));

        $seller = $this->seller();
        $this->entry($seller, 'delete', 'sales', 'Deleted Sale INV-00009');
        $this->entry($seller, 'create', 'products', 'Created Product Pilot Pen');

        $said = $this->feed->visible($this->admin->fresh())->pluck('description')->all();

        $this->assertSame(
            ['Deleted Sale INV-00009'],
            $said,
            'A silenced bell either swallowed the deleted invoice or let the news through anyway.'
        );
    }

    /** A hundred sales a day is a bell nobody reads, so routine is off by default. */
    public function test_the_days_sales_do_not_ring(): void
    {
        $seller = $this->seller();

        $this->entry($seller, 'create', 'sales', 'Created Sale INV-00009');

        $this->assertSame(
            Notifications::ROUTINE,
            Notifications::tierFor('create', 'sales'),
        );

        $this->assertSame(
            0,
            $this->feed->visible($this->admin)->count(),
            'The bell rang for an ordinary sale. In a busy shop that is a hundred rings a day, '
            .'and the alert that mattered is buried in them.'
        );
    }

    /**
     * ⚠️ A new employee does not inherit the shop's whole history.
     *
     * Their mark is null, and the naive reading of null is "everything ever" —
     * which would greet somebody on their first morning with a badge saying
     * 4,000. Nobody reads a badge that says 4,000.
     */
    public function test_somebody_new_does_not_start_with_every_entry_the_shop_ever_wrote(): void
    {
        // Written before the new person existed, which is the whole point: it
        // happened, but not to them.
        $this->entry($this->admin, 'create', 'products', 'Created Product Old Thing');

        $seller = $this->seller();

        $this->assertSame(
            0,
            $this->feed->unreadCount($seller),
            'Somebody who joined this week arrived to unread entries from last year.'
        );

        $this->entry($this->admin, 'create', 'products', 'Created Product New Thing');

        $this->assertSame(1, $this->feed->unreadCount($seller));
    }

    /** Opening it is reading it. */
    public function test_opening_the_list_moves_the_reading_mark(): void
    {
        $seller = $this->seller();
        $this->entry($this->admin, 'create', 'products', 'Created Product Pilot Pen');

        $this->assertSame(1, $this->feed->unreadCount($seller));

        $this->actingAs($seller)->get(route('notifications.index'))->assertOk();

        $this->assertSame(
            0,
            $this->feed->unreadCount($seller->fresh()),
            'Reading the notifications page left everything on it still unread.'
        );
    }

    /** The page shows what was new when it was opened, not an emptied list. */
    public function test_the_page_still_marks_what_was_new_when_it_was_opened(): void
    {
        $seller = $this->seller();
        $this->entry($this->admin, 'create', 'products', 'Created Product Pilot Pen');

        $this->actingAs($seller)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Created Product Pilot Pen')
            ->assertSee('is-unread', escape: false);
    }

    /** The poll hands back data for the browser to build rows from. */
    public function test_the_poll_returns_values_and_not_markup(): void
    {
        $seller = $this->seller();
        $this->entry($this->admin, 'create', 'products', 'Created Product Pilot Pen');

        $response = $this->actingAs($seller)->getJson(route('notifications.feed'))->assertOk();

        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('items.0.text', 'Created Product Pilot Pen');
        $response->assertJsonPath('items.0.unread', true);
        $response->assertJsonPath('items.0.who', $this->admin->name);

        foreach ($response->json('items') as $item) {
            $this->assertArrayNotHasKey('html', $item);
            $this->assertStringNotContainsString('<', (string) $item['text']);
        }
    }

    /**
     * ⚠️ What one person types is drawn on another person's screen.
     *
     * A product named `<img src=x onerror=…>` reaches the admin's bell through
     * the feed. The rows are built with createElement and textContent for
     * exactly that reason, and this is what stops somebody "simplifying" it
     * back to innerHTML.
     */
    public function test_the_browser_builds_bell_rows_without_innerhtml(): void
    {
        $js = file_get_contents(base_path('resources/js/app.js'));

        $start = strpos($js, 'The bell, kept live');
        $this->assertNotFalse($start, 'The bell’s script is gone from app.js.');

        // Comments stripped first — the block's own docblock says the word
        // `innerHTML` while explaining why it is not used, and a test that
        // cannot tell a warning from the thing it warns about is no test.
        $bell = preg_replace('#^\s*(//|/\*|\*).*$#m', '', substr($js, $start));

        $this->assertStringContainsString('textContent = item.text', $bell);

        // ⚠️ The count goes into its own span, because the badge also holds a
        // visually-hidden "unread" for a screen reader. Writing the number over
        // the whole badge dropped that word the first time the poll ran, and
        // the badge then read as a bare "3" to anybody not looking at it.
        $this->assertStringContainsString('number.textContent', $bell);
        $this->assertStringNotContainsString('badge.textContent', $bell);
        $this->assertStringNotContainsString('innerHTML', $bell);
        $this->assertStringNotContainsString('insertAdjacentHTML', $bell);
    }

    /** A notification that opens a 404 is worse than one that opens nothing. */
    public function test_a_deleted_record_is_not_given_a_link(): void
    {
        $this->assertNotNull(Notifications::linkFor('sales', 'create', 5));
        $this->assertNull(
            Notifications::linkFor('sales', 'delete', 5),
            'The bell offered a link to an invoice that has been deleted.'
        );
        $this->assertNull(
            Notifications::linkFor('settings', 'update', 1),
            'The bell invented a URL for a module with no screen to show one record.'
        );
    }

    /** Silencing news leaves alerts; it is not a setting that can lock somebody out. */
    public function test_the_preference_form_cannot_switch_alerts_off(): void
    {
        $this->actingAs($this->admin)
            ->post(route('preferences.notifications'), ['alert' => '0', 'news' => '0', 'routine' => '0'])
            ->assertRedirect();

        $this->assertSame('alert', $this->admin->fresh()->notify_tiers);
    }

    /** And it does record the tiers somebody did ask for. */
    public function test_the_preference_form_records_what_was_asked_for(): void
    {
        $this->actingAs($this->admin)
            ->post(route('preferences.notifications'), ['news' => '1', 'routine' => '1'])
            ->assertRedirect();

        $this->assertSame('alert,news,routine', $this->admin->fresh()->notify_tiers);
    }

    /** Every signed-in person has a bell, whatever they hold. */
    public function test_the_bell_is_drawn_for_a_reader_holding_almost_nothing(): void
    {
        // Products, not the dashboard: a shop assistant does not hold
        // dashboard.view, and the whole point is that the bell reaches the
        // reader who holds the fewest permissions in the shop.
        $this->actingAs($this->seller())->get(route('products.index'))
            ->assertOk()
            ->assertSee('app-bell', escape: false);
    }

    /**
     * Somebody who stands at the counter and nothing more.
     *
     * ⚠️ Deliberately NARROWER than User::DEFAULT_PERMISSIONS, which includes
     * `purchases.view`. The permission that matters to these tests is the one
     * this person does NOT hold, and a set that happens to include it would
     * make every assertion below pass for the wrong reason.
     *
     * This is a real shape, not a contrivance: it is what an admin leaves a
     * salesperson with when the shop does not want its buying prices read at
     * the till.
     */
    private const COUNTER = [
        'auth.login',
        'sales.create', 'sales.view',
        'products.view', 'customers.view',
    ];

    private function seller(): User
    {
        $seller = User::create([
            'name' => 'Hawkar', 'email' => 'hawkar@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER, 'is_active' => true,
        ]);

        $seller->permissions()->sync(Permission::whereIn('key', self::COUNTER)->pluck('id'));

        $this->assertFalse(
            $seller->fresh()->load('permissions')->hasPermission('purchases.view'),
            'These tests turn on this person NOT being able to open purchases.'
        );

        return $seller->fresh()->load('permissions');
    }

    private function entry(
        User $by,
        string $action,
        string $module,
        string $description,
        ?string $tier = null,
        ?string $ip = null,
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => $by->id,
            'action' => $action,
            'module' => $module,
            'tier' => $tier ?? Notifications::tierFor($action, $module),
            'description' => $description,
            'ip_address' => $ip,
        ]);
    }
}
