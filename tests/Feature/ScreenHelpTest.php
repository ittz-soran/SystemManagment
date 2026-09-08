<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Support\ScreenHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Help that reaches the person while they are stuck.
 *
 * A guide page is the thing everybody builds and nobody opens: an assistant
 * halfway through a sale does not leave the till to read a manual. So this is
 * the part that matters — one button, opening the help for this screen and no
 * other.
 */
class ScreenHelpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /**
     * Every screen it claims to help with is a screen that exists.
     *
     * The key is a route name, which is the only honest key — but a renamed or
     * deleted route would leave help attached to nothing, silently, and the
     * button would simply stop appearing on a page that still needs it.
     */
    public function test_every_screen_it_helps_with_is_a_real_route(): void
    {
        foreach (array_keys(ScreenHelp::all()) as $route) {
            $this->assertTrue(Route::has($route), "help is written for [{$route}], which is not a route");
        }
    }

    /** The six screens people get stuck on, named so the set cannot quietly shrink. */
    public function test_it_covers_the_screens_that_were_agreed(): void
    {
        $this->assertSame([
            'sales.create',
            'purchases.create',
            'sale-returns.create',
            'purchase-returns.create',
            'stock-adjustments.index',
            'reports.index',
        ], array_keys(ScreenHelp::all()));
    }

    /** An entry with no title is an empty drawer with a heading. */
    public function test_every_entry_is_worth_opening(): void
    {
        foreach (ScreenHelp::all() as $route => $help) {
            $this->assertArrayHasKey('title', $help, $route);
            $this->assertNotSame('', trim($help['title']), $route);
            $this->assertArrayHasKey('intro', $help, $route);

            // A screen worth a button is a screen worth more than one sentence.
            $this->assertNotEmpty($help['steps'] ?? [], "{$route} has no steps");

            foreach ($help['steps'] as $step) {
                $this->assertCount(2, $step, "{$route}: a step is a heading and a line");
            }

            if (isset($help['warning'])) {
                $this->assertCount(2, $help['warning'], "{$route}: a warning is a heading and a line");
            }
        }
    }

    public function test_the_button_and_the_panel_are_on_a_screen_that_has_help(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee(__('Help for this screen'))
            ->assertSee('id="screen-help"', false)
            ->assertSee(__('Stock only exists once you have recorded a purchase. A new shop has to buy before it can sell — that is how the system knows what each item cost you.'), false);
    }

    /** No button where there is nothing to say, rather than one that opens nothing. */
    public function test_a_screen_with_no_help_gets_no_button(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('Help for this screen'))
            ->assertDontSee('id="screen-help"', false);
    }

    /**
     * The reader most likely to need help holds the fewest permissions.
     *
     * So the button carries none of its own. A new assistant given only the
     * sale screen still gets the help for it.
     */
    public function test_a_user_with_one_permission_still_gets_the_help(): void
    {
        $user = User::create([
            'name' => 'Karwan', 'email' => 'karwan@shop.iq',
            'password' => 'correct-horse-battery', 'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $user->permissions()->sync(
            Permission::whereIn('key', ['sales.create'])->pluck('id'),
        );

        $this->actingAs($user)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee(__('Help for this screen'));
    }

    /**
     * The panel arrives from the end of the line, whichever end that is.
     *
     * This app imports Bootstrap's LTR build and flips it with dir="rtl",
     * which works for everything laid out with logical properties. Bootstrap
     * pins `.offcanvas-end` with a physical `right: 0`, so in Sorani the help
     * slid in over the sidebar while the screen it was explaining stayed
     * uncovered — found by opening it in Sorani, not by reading the CSS.
     *
     * Asserted against the stylesheet because a lost rule here is invisible:
     * every test still passes and the panel simply appears on the wrong side
     * for three of the four languages.
     */
    public function test_the_panel_flips_for_right_to_left_languages(): void
    {
        $scss = file_get_contents(resource_path('scss/app.scss'));

        $this->assertMatchesRegularExpression(
            '/\[dir=.rtl.\]\s+\.offcanvas-end\s*\{[^}]*left:\s*0/s',
            $scss,
            'the help panel must open from the left in RTL, where the content is',
        );
    }

    /** It follows the reader's language, like everything else on the page. */
    public function test_the_help_is_translated(): void
    {
        $this->admin->update(['language' => 'ckb']);

        $this->actingAs($this->admin)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee('یارمەتی بۆ ئەم شاشەیە', false)
            ->assertDontSee('Help for this screen');
    }
}
