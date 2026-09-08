<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CalendarNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * The clock, written the way the reader writes.
 *
 * "AM" beside a Kurdish date reads like a fault, and no browser will produce
 * the Kurdish month names Soran uses — Intl either has no Sorani data at all or
 * gives the Arabic-derived names off an Iraqi invoice. So the names travel to
 * the page as ordinary translations, and this is what holds them: a month left
 * behind is not a crash, it is one English word in the middle of a Kurdish
 * shop, which nobody reports and everybody notices.
 */
class CalendarNamesTest extends TestCase
{
    use RefreshDatabase;

    private const LANGUAGES = ['en', 'ckb', 'ar', 'fa'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_there_are_seven_days_and_twelve_months_in_every_language(): void
    {
        foreach (self::LANGUAGES as $language) {
            App::setLocale($language);

            $this->assertCount(7, CalendarNames::weekdays(), $language);
            $this->assertCount(12, CalendarNames::months(), $language);
            $this->assertCount(2, CalendarNames::meridiem(), $language);

            foreach ([...CalendarNames::weekdays(), ...CalendarNames::months(), ...CalendarNames::meridiem()] as $name) {
                $this->assertNotSame('', trim($name), "{$language} has an empty name");
            }
        }
    }

    /**
     * Sunday first and January first, because JavaScript counts that way.
     *
     * `getDay()` returns 0 for Sunday and `getMonth()` returns 0 for January.
     * An off-by-one here writes Tuesday on a Wednesday, every day, quietly.
     */
    public function test_the_order_matches_what_javascript_hands_the_clock(): void
    {
        App::setLocale('en');

        $this->assertSame('Sunday', CalendarNames::weekdays()[0]);
        $this->assertSame('Saturday', CalendarNames::weekdays()[6]);
        $this->assertSame('January', CalendarNames::months()[0]);
        $this->assertSame('December', CalendarNames::months()[11]);

        // The date Soran wrote out by hand: Wednesday 9 September 2026.
        App::setLocale('ckb');
        $this->assertSame('چوارشەممە', CalendarNames::weekdays()[3]);
        $this->assertSame('سەرماوەز', CalendarNames::months()[8]);
    }

    /**
     * A format that lost a placeholder loses that part of the date, silently.
     *
     * The order and the joining words differ per language — Kurdish puts an
     * izafe on the day, ٩ی سەرماوەز — so the whole pattern is translated rather
     * than assembled. Which means a translator can drop `:year` and nothing but
     * this will say so.
     */
    public function test_every_language_keeps_all_four_parts_of_the_date(): void
    {
        foreach (self::LANGUAGES as $language) {
            App::setLocale($language);

            $format = CalendarNames::dateFormat();

            foreach ([':weekday', ':day', ':month', ':year'] as $part) {
                $this->assertStringContainsString($part, $format, "{$language} dropped {$part}");
            }

            foreach ([':time', ':meridiem'] as $part) {
                $this->assertStringContainsString($part, CalendarNames::timeFormat(), "{$language} dropped {$part}");
            }
        }
    }

    /** The clock cannot draw what never reached the page. */
    public function test_the_names_reach_the_topbar(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin->update(['language' => 'ckb', 'date_language' => 'interface', 'clock_24_hour' => false]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-weekdays', false)
            ->assertSee('data-months', false)
            ->assertSee('سەرماوەز', false)
            ->assertSee('سەرلەبەیانی', false)
            ->assertSee('data-hour12="1"', false);
    }

    /** Twenty-four hours means no am/pm reaches the page at all. */
    public function test_the_twenty_four_hour_clock_turns_the_meridiem_off(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin->update(['language' => 'ckb', 'clock_24_hour' => true]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-hour12=""', false);
    }

    /** English dates in a Kurdish shop: two questions, two answers. */
    public function test_dates_can_be_kept_in_english_while_the_shop_is_not(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin->update(['language' => 'ckb', 'date_language' => 'english']);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-english="1"', false);
    }

    public function test_the_preferences_are_saved_and_the_switch_can_be_turned_off(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->patch(route('preferences.update'), [
            'language' => 'ckb', 'theme' => 'light', 'items_per_page' => 25,
            'date_language' => 'english', 'clock_24_hour' => '1',
        ])->assertRedirect();

        $this->assertSame('english', $admin->fresh()->date_language);
        $this->assertTrue($admin->fresh()->clock_24_hour);

        // An unchecked switch sends nothing; it must still mean "off" rather
        // than "unchanged", or it could never be turned back off.
        $this->actingAs($admin)->patch(route('preferences.update'), [
            'language' => 'ckb', 'theme' => 'light', 'items_per_page' => 25,
            'date_language' => 'interface',
        ])->assertRedirect();

        $this->assertFalse($admin->fresh()->clock_24_hour);
    }

    public function test_an_invented_date_language_is_refused(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->patch(route('preferences.update'), [
            'language' => 'ckb', 'theme' => 'light', 'items_per_page' => 25,
            'date_language' => 'klingon',
        ])->assertSessionHasErrors('date_language');
    }
}
