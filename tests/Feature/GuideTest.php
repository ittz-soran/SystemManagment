<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Support\Guide;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The guide: the reference, arranged by what somebody is trying to do.
 *
 * Two things this holds that nothing else can. The guide must open for the
 * reader holding the fewest permissions in the shop, because that is who needs
 * it — and the one link it offers out to a real screen must still be checked,
 * because a manual that sends somebody to "access denied" has taught them the
 * manual is wrong.
 */
class GuideTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** A user with one permission and nothing else. */
    private function assistant(array $permissions = []): User
    {
        $user = User::create([
            'name' => 'Karwan', 'email' => 'karwan@shop.iq',
            'password' => 'correct-horse-battery', 'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $user->permissions()->sync(
            Permission::whereIn('key', $permissions)->pluck('id'),
        );

        return $user;
    }

    public function test_the_guide_lists_every_topic_under_its_own_heading(): void
    {
        $response = $this->actingAs($this->admin)->get(route('guide.index'))->assertOk();

        foreach (Guide::groups() as $heading) {
            $response->assertSee($heading, false);
        }

        foreach (Guide::topics() as $topic) {
            $response->assertSee($topic['title'], false);
        }
    }

    public function test_a_topic_opens_and_shows_its_body(): void
    {
        $this->actingAs($this->admin)
            ->get(route('guide.show', 'two-costs'))
            ->assertOk()
            ->assertSee(__('Why the same product has two costs'), false)
            ->assertSee(__('Sell twelve and the system takes the ten old ones and two of the new. That is not a preference — it is how the profit on those twelve is worked out, and it is what makes the figure match the money in the drawer.'), false);
    }

    /** A guide URL kept from an older version is a 404, not a blank page. */
    public function test_a_topic_that_does_not_exist_is_not_found(): void
    {
        $this->actingAs($this->admin)
            ->get(route('guide.show', 'how-to-fly'))
            ->assertNotFound();
    }

    /**
     * The whole point of the page, asserted.
     *
     * The reader most likely to open the guide is the newest assistant, who
     * holds the fewest permissions in the shop. A manual behind a permission is
     * not a manual.
     */
    public function test_a_user_with_no_permissions_at_all_can_still_read_it(): void
    {
        $user = $this->assistant();

        $this->actingAs($user)->get(route('guide.index'))->assertOk();

        foreach (array_keys(Guide::topics()) as $slug) {
            $this->actingAs($user)->get(route('guide.show', $slug))->assertOk();
        }
    }

    public function test_it_is_in_the_sidebar_for_somebody_who_can_open_nothing_else(): void
    {
        $pages = collect(Navigation::pagesFor($this->assistant()))->pluck('route');

        $this->assertContains('guide.index', $pages);
    }

    /**
     * Section 9b: never show a link that leads to "access denied".
     *
     * Everything else in this system settles that by hiding the whole screen.
     * The guide cannot — it is open to everybody — so the one link out of a
     * topic is the single place the question has to be asked per reader.
     */
    public function test_the_link_to_a_screen_is_offered_only_to_somebody_who_may_open_it(): void
    {
        $this->actingAs($this->admin)
            ->get(route('guide.show', 'invoice-details'))
            ->assertOk()
            ->assertSee(__('Open this screen'));

        $this->actingAs($this->assistant(['sales.create']))
            ->get(route('guide.show', 'invoice-details'))
            ->assertOk()
            ->assertDontSee(__('Open this screen'));
    }

    /** Every screen a topic offers to open is a screen that exists. */
    public function test_every_link_points_at_a_real_route_and_a_real_permission(): void
    {
        $keys = Permission::pluck('key')->all();

        foreach (Guide::topics() as $slug => $topic) {
            $this->assertArrayHasKey('permission', $topic, $slug);

            if (! isset($topic['route'])) {
                $this->assertNull($topic['permission'], "{$slug} names a permission but no screen");

                continue;
            }

            $this->assertTrue(Route::has($topic['route']), "{$slug} links to [{$topic['route']}], which is not a route");

            // A route with a parameter cannot be linked from a page that has no
            // record in front of it — `route()` throws and the whole guide
            // topic answers 500. It is the one way a wrong link here takes the
            // page down rather than merely going somewhere unhelpful.
            $this->assertEmpty(
                Route::getRoutes()->getByName($topic['route'])->parameterNames(),
                "{$slug} links to [{$topic['route']}], which needs a record in its URL",
            );

            if ($topic['permission'] !== null) {
                $this->assertContains($topic['permission'], $keys, "{$slug} names a permission that does not exist");
            }
        }
    }

    /** A topic in a heading that is not on the page would never be read. */
    public function test_every_topic_sits_in_a_group_that_is_shown(): void
    {
        $groups = array_keys(Guide::groups());

        foreach (Guide::topics() as $slug => $topic) {
            $this->assertContains($topic['group'], $groups, $slug);
        }
    }

    /** An entry with no body is a title that wastes a click. */
    public function test_every_topic_is_worth_opening(): void
    {
        foreach (Guide::topics() as $slug => $topic) {
            $this->assertNotSame('', trim($topic['title']), $slug);
            $this->assertNotSame('', trim($topic['blurb']), $slug);
            $this->assertGreaterThan(0, $topic['minutes'], $slug);
            $this->assertNotEmpty($topic['sections'], "{$slug} has no body");

            foreach ($topic['sections'] as $section) {
                $this->assertCount(2, $section, "{$slug}: a section is a heading and its paragraphs");
                $this->assertNotEmpty($section[1], "{$slug}: [{$section[0]}] has no paragraphs");
            }
        }
    }

    /**
     * The search box filters in the browser, over the whole of each topic.
     *
     * Somebody looking for "barcode" should find the sale topic even though the
     * word is three screens into it — which only works if the body text reaches
     * the page in data-search. It is the kind of thing that silently degrades
     * into a title-only search, so it is asserted rather than assumed.
     */
    public function test_the_search_can_see_words_from_inside_a_topic(): void
    {
        $this->assertStringContainsString(
            'barcode',
            Guide::searchText(Guide::topic('making-a-sale')),
        );

        $this->actingAs($this->admin)
            ->get(route('guide.index'))
            ->assertOk()
            ->assertSee('data-search', false)
            ->assertSee('barcode reader is the fast way', false);
    }

    public function test_the_first_week_is_offered_before_the_list(): void
    {
        $this->assertArrayHasKey(Guide::FIRST, Guide::topics());

        $this->actingAs($this->admin)
            ->get(route('guide.index'))
            ->assertOk()
            ->assertSee(__('New here?'), false);
    }

    /** Said in the shop's words, with a date, or it is a changelog nobody reads. */
    public function test_whats_new_is_dated_and_written_for_a_shopkeeper(): void
    {
        $new = Guide::whatsNew();

        $this->assertNotEmpty($new);

        $dates = array_column($new, 'date');
        $sorted = $dates;
        rsort($sorted);

        $this->assertSame($sorted, $dates, 'newest first');

        foreach ($new as $item) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $item['date']);
            $this->assertNotSame('', trim($item['title']));
            $this->assertNotSame('', trim($item['body']));
        }

        $this->actingAs($this->admin)
            ->get(route('guide.index'))
            ->assertOk()
            ->assertSee(__('What’s new'), false)
            ->assertSee($new[0]['title'], false);
    }

    /** The ? panel is where somebody gets stuck; this is where they go next. */
    public function test_the_help_panel_offers_the_way_here(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee(__('Open the full guide'))
            ->assertSee(route('guide.index'), false);
    }

    /** A topic names the rest of its group, so one answer is not a dead end. */
    public function test_a_topic_offers_its_neighbours(): void
    {
        $this->actingAs($this->admin)
            ->get(route('guide.show', 'customer-returns'))
            ->assertOk()
            ->assertSee(__('Making a sale'), false)
            ->assertSee(__('Letting a customer pay later'), false)
            // Not itself, in its own sidebar.
            ->assertSee(__('All guides'));
    }

    /** It follows the reader's language, like everything else on the page. */
    public function test_the_guide_is_translated(): void
    {
        $this->admin->update(['language' => 'ckb']);

        $this->actingAs($this->admin)
            ->get(route('guide.index'))
            ->assertOk()
            ->assertDontSee('New here?')
            ->assertDontSee('Getting started');
    }
}
