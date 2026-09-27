<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DataIntegrityService;
use App\Services\ShopHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One page that checks the whole machine — Soran, 2026-09-27.
 *
 * ⚠️ **A diagnostics page is the last page in the shop allowed to lie.** Two
 * ways it can: by answering with a stack trace when one check throws, and by
 * crying wolf about something that is perfectly fine. The second is the worse
 * one — a red badge nobody believes makes the next red badge invisible, and a
 * first version of the PHP check flagged `bcmath`, which this shop requires
 * nowhere and calls nowhere.
 */
class ShopHealthTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_it_reports_the_machine_in_sections(): void
    {
        $health = app(ShopHealth::class)->run();

        $this->assertSame(
            ['licence', 'storage', 'codebase'],
            array_keys($health['sections']),
        );

        foreach ($health['sections'] as $section) {
            $this->assertNotSame([], $section['checks'], 'an empty section is a heading that says nothing');

            foreach ($section['checks'] as $check) {
                // The same shape the accounting checks use, so one page renders both.
                foreach (['key', 'title', 'because', 'severity', 'examples', 'repair', 'note'] as $field) {
                    $this->assertArrayHasKey($field, $check, "a check with no {$field}");
                }

                $this->assertNotSame('', $check['because'], $check['key'].' does not say why it matters');
            }
        }
    }

    /**
     * ⚠️ **A healthy container must come back healthy.** This one has a
     * licence it does not need, disk, writable folders and every migration run
     * — the only honest complaint is that no backup has ever been taken.
     */
    public function test_a_sound_shop_is_not_told_it_is_broken(): void
    {
        $health = app(ShopHealth::class)->run();

        $complaints = [];

        foreach ($health['sections'] as $section) {
            foreach ($section['checks'] as $check) {
                if ($check['severity'] !== ShopHealth::OK) {
                    $complaints[] = $check['key'];
                }
            }
        }

        $this->assertSame(['backup_age'], $complaints,
            'something healthy is being reported as a fault: '.implode(', ', $complaints));
    }

    /**
     * ⚠️ **Every extension named must be one the code actually calls.** The
     * check that listed `bcmath` from memory would have put a red "PHP is
     * broken" on a shop whose PHP was fine.
     */
    public function test_it_only_asks_for_extensions_this_shop_really_uses(): void
    {
        $source = file_get_contents(app_path('Services/ShopHealth.php'));

        preg_match('/\\$needed = \\[(.*?)\\];/s', $source, $m);
        $this->assertNotEmpty($m, 'the extension list could not be read');

        preg_match_all("/'([a-z]+)' =>/", $m[1], $found);

        foreach ($found[1] as $extension) {
            $this->assertTrue(
                extension_loaded($extension),
                "ShopHealth asks for the {$extension} extension, which this shop does not have — "
                .'either it is genuinely needed and composer.json should say so, or it was listed from memory.'
            );
        }
    }

    /**
     * ⚠️ **One check throwing costs that check, never the page.**
     *
     * Reached through reflection because `attempt()` is the guarantee itself,
     * and the class is final on purpose — a diagnostics page is the last page
     * allowed to answer with a stack trace, and this is the line that keeps
     * that promise. It is the same reasoning `DataIntegrityService::attempt()`
     * carries, after `lines` — a reserved word on MariaDB — took that whole
     * page down on a shop whose data was fine.
     */
    public function test_a_check_that_throws_costs_only_itself(): void
    {
        $health = app(ShopHealth::class);

        $attempt = new \ReflectionMethod($health, 'attempt');
        $attempt->setAccessible(true);

        $answer = $attempt->invoke($health, 'disk_space', function () {
            throw new \RuntimeException('the host has disabled this');
        });

        $this->assertSame(ShopHealth::UNAVAILABLE, $answer['severity']);
        $this->assertStringContainsString('the host has disabled this', $answer['examples'][0]['says']);

        // ⚠️ And it still says WHICH check was lost, rather than a bare error.
        $this->assertSame(__('There is disk left to write to'), $answer['title']);

        // The page itself is unharmed.
        $this->actingAs($this->user())->get(route('settings.data-check'))->assertOk();
    }

    /** The severities are the ones the page already knows how to draw. */
    public function test_it_speaks_the_words_the_page_already_draws(): void
    {
        $this->assertSame(DataIntegrityService::OK, ShopHealth::OK);
        $this->assertSame(DataIntegrityService::REBUILDABLE, ShopHealth::NOTICE);
        $this->assertSame(DataIntegrityService::SERIOUS, ShopHealth::SERIOUS);
        $this->assertSame(DataIntegrityService::UNAVAILABLE, ShopHealth::UNAVAILABLE);
    }

    // ---- The page -----------------------------------------------------------

    public function test_the_page_shows_all_five_sections(): void
    {
        $page = $this->actingAs($this->user())->get(route('settings.data-check'))->assertOk();

        foreach ([__('Accounting'), __('Licence'), __('Storage'), __('Codebase'), __('Numbers')] as $section) {
            $page->assertSee($section);
        }

        $page->assertSee(__('Shop health'));
    }

    /**
     * ⚠️ **The verdict counts the machine too.** A green banner over a licence
     * that lapsed yesterday is the page telling a comfortable lie — so the
     * header must move when only a machine check fails, and in this container
     * exactly one does.
     */
    public function test_the_verdict_counts_the_machine_checks_as_well(): void
    {
        $accounting = app(DataIntegrityService::class)->run();
        $machine = app(ShopHealth::class)->run();

        $this->assertSame(0, $accounting['serious'], 'the fixture shop must be sound on its books');
        $this->assertGreaterThan(0, $machine['serious'], 'and have one machine complaint to carry up');

        $this->actingAs($this->user())->get(route('settings.data-check'))
            ->assertOk()
            // The banner speaks, although nothing is wrong with the books.
            ->assertSee(trans_choice(
                '{1}One thing here cannot be right.|[2,*]:count things here cannot be right.',
                $machine['serious'], ['count' => number_format($machine['serious'])],
            ));
    }

    /** ⚠️ Numbers is informational, so it never carries a verdict. */
    public function test_numbers_is_not_dressed_as_a_pass_or_fail(): void
    {
        $numbers = app(ShopHealth::class)->numbers();

        $this->assertArrayHasKey(__('Products'), $numbers);
        $this->assertArrayHasKey(__('Stock movements'), $numbers);

        $this->actingAs($this->user())->get(route('settings.data-check'))
            ->assertOk()
            ->assertSee(__('How big this shop is. Nothing here is right or wrong — it is the first thing to look at when something has become slow.'));
    }
}
