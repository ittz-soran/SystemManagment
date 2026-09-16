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

    /**
     * ⚠️ Nothing about this ever opens by itself.
     *
     * Soran's rule: *"never a dialog over the till"*. The script may only react
     * to somebody pressing something — no timer, no auto-open, no modal.
     */
    public function test_the_remembrances_never_open_themselves(): void
    {
        $js = file_get_contents(base_path('resources/js/app.js'));

        $start = strpos($js, 'Tapping a remembrance');
        $this->assertNotFalse($start, 'The remembrance script is gone from app.js.');

        $block = preg_replace('#^\s*(//|/\*|\*).*$#m', '', substr($js, $start));

        foreach (['setInterval', 'setTimeout', '.show()', 'Modal', 'Toast'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $block,
                "The remembrances must never appear on their own — `{$forbidden}` is how that starts."
            );
        }
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
