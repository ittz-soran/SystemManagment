<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Adhkar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The remembrances — أذكار.
 *
 * **Soran, 2026-09-15:** *"add islamic Remembrance for ex from morning show
 * Morning Remembrances … or all short duas remembrance"*, followed by
 * seventeen of them run together in one message, and a screenshot confirming
 * how I had split them.
 *
 * ⚠️ **The first test is the important one and it is not about code.** This is
 * religious text. A dropped tashkeel mark is not a cosmetic bug, it is a
 * different word, and nothing else in this system would notice. So what he
 * sent is held here, separately from the class that carries the split, and the
 * two are compared character for character on every run.
 */
class AdhkarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ Soran's message, exactly as it arrived, with no line breaks — that is
     * how he sent it. It lives here and NOWHERE else, so that the split in
     * Adhkar::SEEDED has something independent to be checked against.
     */
    private const AS_HE_SENT_IT = 'لَا إِلَهَ إِلَّا اللهُ مُحَمَّدٌ رَسُولُ اللهِسُبْحَانَ اللهِ وَبِحَمْدِهِسُبْحَانَ اللهِ الْعَظِيمِأَسْتَغْفِرُ اللهَ وَأَتُوبُ إِلَيْهِلَا حَوْلَ وَلَا قُوَّةَ إِلَّا بِاللهِالْحَمْدُ للهِ حَمْدًا كَثِيرًااللَّهُمَّ صَلِّ عَلَى مُحَمَّدٍالْحَمْدُ للهِ عَلَى كُلِّ حَالٍحَسْبُنَا اللهُ وَنِعْمَ الْوَكِيلُسُبْحَانَ اللهِ وَالْحَمْدُ للهِاللهُ أَكْبَرُ كَبِيرًااللَّهُمَّ إِنَّكَ عَفُوٌّ تُحِبُّ الْعَفْوَ فَاعْفُ عَنِّييَا حَيُّ يَا قَيُّومُ بِرَحْمَتِكَ أَسْتَغِيثُرَبِّ اغْفِرْ لِي وَلِوَالِدَيَّاللَّهُمَّ أَجِرْنِي مِنَ النَّارِاللَّهُمَّ صَلِّ وَسَلِّمْ عَلَى نَبِيِّنَا مُحَمَّدٍعَلَيْهِ الصَّلَاةُ وَالسَّلَامُ';

    public function test_the_seeded_adhkar_are_his_text_character_for_character(): void
    {
        $lines = Adhkar::parse(Adhkar::SEEDED);

        $this->assertCount(17, $lines, 'The seventeen he sent are no longer seventeen.');

        $this->assertSame(
            self::AS_HE_SENT_IT,
            implode('', $lines),
            'The seeded adhkar no longer reassemble into what Soran sent. This is Qur’anic and '
            .'prophetic text: a changed mark is a changed word, and the only permitted edit to '
            .'that string was inserting the breaks between phrases.'
        );
    }

    /**
     * ⚠️ The bug this whole file exists to have caught once.
     *
     * `preg_split('/\R/')` without the `u` flag works in BYTES, and matches
     * 0x85 — an ordinary continuation byte inside an Arabic letter. It turned
     * the seventeen into thirty-two and cut forty-two characters out of the
     * middle of words, silently. Units::parse() carries the same line and is
     * safe only because a unit is spelled "kg".
     */
    public function test_splitting_lines_does_not_cut_through_arabic_letters(): void
    {
        $written = "سُبْحَانَ اللهِ وَبِحَمْدِهِ\nالْحَمْدُ للهِ عَلَى كُلِّ حَالٍ";

        $lines = Adhkar::parse($written);

        $this->assertCount(2, $lines);
        $this->assertSame('سُبْحَانَ اللهِ وَبِحَمْدِهِ', $lines[0]);
        $this->assertSame('الْحَمْدُ للهِ عَلَى كُلِّ حَالٍ', $lines[1]);
    }

    /** Tidying trims the ends of a line and touches nothing inside it. */
    public function test_nothing_inside_a_line_is_tidied(): void
    {
        $dhikr = 'لَا حَوْلَ  وَلَا قُوَّةَ إِلَّا بِاللهِ';

        $this->assertSame([$dhikr], Adhkar::parse("  {$dhikr}  \n\n"));
    }

    /** Morning and evening start empty: they are the shop's to write. */
    public function test_only_the_any_time_list_is_seeded(): void
    {
        $this->assertCount(17, Adhkar::list(Adhkar::ANY));
        $this->assertSame([], Adhkar::list(Adhkar::MORNING));
        $this->assertSame([], Adhkar::list(Adhkar::EVENING));
    }

    /**
     * ⚠️ The shop's clock, not the server's.
     *
     * A cPanel account in Germany serving a shop in Sulaymaniyah would call it
     * morning at nine in the evening — and the hour is the entire point of this
     * feature.
     */
    public function test_the_window_follows_the_shops_own_timezone(): void
    {
        Setting::updateOrCreate(['key' => 'timezone'], ['value' => 'Asia/Baghdad']);
        Setting::updateOrCreate(['key' => 'adhkar_morning'], ['value' => 'اللَّهُمَّ بِكَ أَصْبَحْنَا']);

        // 04:00 UTC is 07:00 in Baghdad: morning there, the middle of the night
        // where the server happens to be sitting.
        Carbon::setTestNow(Carbon::parse('2026-09-16 04:00:00', 'UTC'));

        $this->assertSame(Adhkar::MORNING, Adhkar::now());

        Carbon::setTestNow();
    }

    /** Nothing written for this window means the any-time list, not an empty panel. */
    public function test_an_unwritten_window_falls_back_rather_than_showing_nothing(): void
    {
        Setting::updateOrCreate(['key' => 'timezone'], ['value' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-09-16 07:00:00', 'UTC'));

        $shown = Adhkar::forNow();

        $this->assertSame(Adhkar::ANY, $shown['window']);
        $this->assertCount(17, $shown['texts']);

        Carbon::setTestNow();
    }

    /**
     * ⚠️ A mistyped window must not take down the shop.
     *
     * This is consulted while drawing the topbar on every page, including the
     * till. Somebody typing "9am to noon" into the box is a setting that does
     * not work, not a shop that does not open.
     */
    public function test_a_nonsense_window_falls_back_instead_of_breaking_every_page(): void
    {
        Setting::updateOrCreate(['key' => 'adhkar_morning_window'], ['value' => '9am to noon']);

        $this->assertSame([5 * 60, 11 * 60], Adhkar::windowFor(Adhkar::MORNING));
    }

    /** A window that ends before it begins would never open, so it is treated as unset. */
    public function test_a_backwards_window_is_treated_as_unset(): void
    {
        Setting::updateOrCreate(['key' => 'adhkar_evening_window'], ['value' => '19:00-15:00']);

        $this->assertSame([15 * 60, 19 * 60], Adhkar::windowFor(Adhkar::EVENING));
    }

    /** The tally is keyed by the words, so reordering the list cannot move counts. */
    public function test_the_tally_is_keyed_by_the_text_and_not_by_position(): void
    {
        $first = Adhkar::key('سُبْحَانَ اللهِ وَبِحَمْدِهِ');

        $this->assertSame($first, Adhkar::key('سُبْحَانَ اللهِ وَبِحَمْدِهِ'));
        $this->assertNotSame($first, Adhkar::key('سُبْحَانَ اللهِ الْعَظِيمِ'));
    }

    // =====================================================================
    // The screens
    // =====================================================================

    /** The tab is beside the bell, and it carries no count. */
    public function test_the_bell_holds_a_remembrance_tab_with_no_badge_on_it(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'))->assertOk();

        $response->assertSee('bell-pane-dhikr', escape: false);
        $response->assertSee('سُبْحَانَ اللهِ وَبِحَمْدِهِ', escape: false);

        /*
         * ⚠️ Soran asked for this tab to have "no red badge". The bell has
         * exactly one badge and it belongs to notifications — so if a second
         * ever appears, somebody has given the remembrances a chore counter.
         */
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'app-bell-count'),
            'A second badge appeared on the bell. The remembrances were asked for without one.'
        );
    }

    /** Turned off, the tab is not there at all — not merely hidden. */
    public function test_somebody_who_turns_it_off_does_not_get_the_tab(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['adhkar_off' => true])->save();

        $this->actingAs($admin->fresh())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('bell-pane-dhikr', escape: false)
            ->assertDontSee('سُبْحَانَ اللهِ وَبِحَمْدِهِ', escape: false);
    }

    /** And the switch is the reader's own, not an admin's to set for them. */
    public function test_the_switch_belongs_to_the_person_who_flipped_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('preferences.remembrance'), ['adhkar' => '0'])
            ->assertRedirect();

        $this->assertTrue((bool) $admin->fresh()->adhkar_off);

        $this->actingAs($admin->fresh())->post(route('preferences.remembrance'), ['adhkar' => '1'])
            ->assertRedirect();

        $this->assertFalse((bool) $admin->fresh()->adhkar_off);
    }

    /** The page shows every list, not only the window whose hour it is. */
    public function test_the_page_shows_every_list(): void
    {
        Setting::updateOrCreate(['key' => 'adhkar_morning'], ['value' => 'اللَّهُمَّ بِكَ أَصْبَحْنَا']);

        $this->actingAs($this->admin())->get(route('remembrance.index'))
            ->assertOk()
            ->assertSee('اللَّهُمَّ بِكَ أَصْبَحْنَا', escape: false)
            ->assertSee('سُبْحَانَ اللهِ وَبِحَمْدِهِ', escape: false);
    }

    /**
     * ⚠️ Settings saves the text without touching a mark of it.
     *
     * The whole hazard of this feature in one test: an admin pastes a dhikr,
     * and what comes back out is not quite the same words.
     */
    public function test_saving_from_settings_changes_not_one_character(): void
    {
        /*
         * ⚠️ All seventeen, not one short one.
         *
         * The first version of this test saved a single dhikr with no double
         * spaces in it — so a "helpful" collapse of whitespace on save passed
         * it, and the test agreed with the bug it existed to catch. What has to
         * survive is the whole of what he sent, through a real form post, which
         * is also the commonest way this would break: an admin opens Settings
         * to change the shop phone and presses Save.
         */
        $this->actingAs($this->admin())
            ->put(route('settings.update'), [
                ...Setting::cached(),
                'units' => ['pcs'],
                'default_unit' => 'pcs',
                'adhkar_any' => Adhkar::SEEDED,
            ])
            ->assertSessionHasNoErrors();

        $saved = Adhkar::list(Adhkar::ANY);

        $this->assertCount(17, $saved);
        $this->assertSame(
            self::AS_HE_SENT_IT,
            implode('', $saved),
            'Saving the Settings page changed the adhkar. Nothing may normalise, strip or '
            .'collapse this text — it is Qur’anic and prophetic, and a changed mark is a '
            .'changed word.'
        );
    }

    /** A mistyped window is refused on the form rather than silently ignored. */
    public function test_settings_refuses_a_window_it_cannot_read(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.update'), [
                ...Setting::cached(),
                'units' => ['pcs'],
                'default_unit' => 'pcs',
                'adhkar_morning_window' => 'sunrise to noon',
            ])
            ->assertSessionHasErrors('adhkar_morning_window');
    }

    // =====================================================================
    // Showing themselves
    // =====================================================================

    /**
     * ⚠️ They now DO open by themselves, and the rule that replaced the old one
     * is narrow.
     *
     * **Soran, 2026-09-16:** *"i want every 1 min or 5 min show on of
     * Remembrances as notification show on screen, without user go to read
     * Remembrance manualy"* — reversing his own earlier *"never a dialog over
     * the till"*. Asked which shape he wanted, he chose: every screen, a small
     * card in the corner, never covering the total or Save.
     *
     * So the guard is no longer "never appears". It is "never appears as
     * something that can take a keystroke or block a sale", which is the part
     * of his first rule that was actually protecting the till.
     */
    public function test_one_that_shows_itself_can_never_block_the_till(): void
    {
        $block = $this->script('A remembrance that shows itself');

        foreach ([
            'Modal' => 'a modal stops the shop until it is dismissed',
            '.focus(' => 'taking focus steals the next keystroke from the cart',
            'alert(' => 'a browser alert freezes the page',
            'confirm(' => 'a browser confirm freezes the page',
            'innerHTML' => 'the adhkar are typed by an admin and drawn on somebody else’s screen',
        ] as $forbidden => $why) {
            $this->assertStringNotContainsString(
                $forbidden,
                $block,
                "A remembrance must not use `{$forbidden}`: {$why}."
            );
        }

        // And it is the corner toast he chose, not something invented.
        $this->assertStringContainsString('bootstrap.Toast', $block);
        $this->assertStringContainsString('textContent', $block);
    }

    /** It keeps quiet while somebody is doing one thing with their attention. */
    public function test_it_waits_for_the_number_pad_and_for_a_tab_nobody_is_watching(): void
    {
        $block = $this->script('A remembrance that shows itself');

        $this->assertStringContainsString(
            "document.querySelector('.modal.show')",
            $block,
            'A remembrance appeared over the number pad, which is where this shop types prices.'
        );

        $this->assertStringContainsString(
            "document.visibilityState !== 'visible'",
            $block,
            'A tab nobody is looking at would queue up ninety of these to fire at once.'
        );
    }

    /**
     * ⚠️ Exactly one list drives the timer.
     *
     * The remembrance page renders the same partial once per window. If they
     * all carried the interval, three timers would run and three cards would
     * arrive together.
     */
    public function test_only_one_list_on_a_page_can_drive_the_timer(): void
    {
        Setting::updateOrCreate(['key' => 'adhkar_morning'], ['value' => 'اللَّهُمَّ بِكَ أَصْبَحْنَا']);

        $page = $this->actingAs($this->admin())->get(route('remembrance.index'))->assertOk();

        $this->assertSame(
            1,
            substr_count($page->getContent(), 'data-every='),
            'More than one list claimed the timer, so several remembrances would arrive at once.'
        );
    }

    /** How often is the reader's own, and 0 is a real answer. */
    public function test_how_often_is_a_preference_and_off_is_one_of_the_choices(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('preferences.remembrance'), ['adhkar' => '1', 'adhkar_every' => '1'])
            ->assertRedirect();

        $this->assertSame(1, (int) $admin->fresh()->adhkar_every);

        $this->actingAs($admin->fresh())
            ->post(route('preferences.remembrance'), ['adhkar' => '1', 'adhkar_every' => '0'])
            ->assertRedirect();

        $this->assertSame(0, (int) $admin->fresh()->adhkar_every);
    }

    /**
     * ⚠️ A number nobody chose is refused.
     *
     * Six seconds is not devotion, it is a screen nobody can work at — and the
     * list exists so that the browser is never asked for one.
     */
    public function test_an_interval_nobody_offered_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('preferences.remembrance'), ['adhkar' => '1', 'adhkar_every' => '1'])
            ->assertRedirect();

        $this->actingAs($admin->fresh())
            ->post(route('preferences.remembrance'), ['adhkar' => '1', 'adhkar_every' => '0.1'])
            ->assertSessionHasErrors('adhkar_every');

        $this->assertSame(1, (int) $admin->fresh()->adhkar_every);
    }

    /**
     * ⚠️ The switch on the remembrance page does not reset how often they come.
     *
     * That form posts `adhkar` alone. Reading a missing field as zero would
     * turn the timer off every time somebody used the show/hide link.
     */
    public function test_hiding_and_showing_leaves_how_often_alone(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['adhkar_every' => 15])->save();

        $this->actingAs($admin->fresh())
            ->post(route('preferences.remembrance'), ['adhkar' => '0'])
            ->assertRedirect();

        $fresh = $admin->fresh();

        $this->assertTrue((bool) $fresh->adhkar_off);
        $this->assertSame(15, (int) $fresh->adhkar_every, 'Hiding the tab silently turned off the timer too.');
    }

    /**
     * ⚠️ The card sits below the topbar, and the rule has to shout to do it.
     *
     * The shared toast container wears Bootstrap's `top-0`, whose utilities are
     * `!important`. Without `!important` here the offset loses silently and one
     * of these lands across the bell, the language switch and the user menu —
     * unclickable for fifteen seconds, every minute. Measured in a browser at
     * 1280×800: topbar ends at 49px, the card starts at 64.
     *
     * The same trap took the till bar's bottom padding once already.
     */
    public function test_the_card_is_pushed_clear_of_the_topbar(): void
    {
        $scss = file_get_contents(base_path('resources/scss/app.scss'));

        $this->assertMatchesRegularExpression(
            '/\.toast-container:has\(\.app-dhikr-toast\)\s*\{[^}]*top:[^;]*!important/s',
            $scss,
            'The offset that keeps a remembrance off the bell has lost its !important, '
            .'so Bootstrap’s top-0 wins and the card covers the topbar controls.'
        );
    }

    /** The block of app.js that drives one feature, with its comments stripped. */
    private function script(string $heading): string
    {
        $js = file_get_contents(base_path('resources/js/app.js'));

        $start = strpos($js, $heading);
        $this->assertNotFalse($start, "The script for “{$heading}” is gone from app.js.");

        $end = strpos($js, '/**', $start);

        // Comments stripped: these blocks explain what they refuse to do, and a
        // test that cannot tell a warning from the thing it warns about is no
        // test. That mistake was made once already, on the bell.
        return preg_replace(
            '#^\s*(//|/\*|\*).*$#m',
            '',
            $end === false ? substr($js, $start) : substr($js, $start, $end - $start),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }
}
