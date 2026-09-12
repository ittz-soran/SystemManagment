<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The line behind each tile's figure — asked for by Soran, 2026-09-12.
 *
 * What is worth testing here is not the drawing. It is that the line is behind
 * the same wall as the number it sits under: **a chart is data too**, and the
 * shape of somebody's purchasing is worth withholding from a reader kept out of
 * the purchases screen. A picture that leaks what the figure withholds is the
 * figure not withheld.
 */
class DashboardSparkTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_gets_a_line_under_the_figures(): void
    {
        $this->seed();

        $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('app-spark', false);
    }

    /**
     * @param  list<string>  $keys
     */
    private function staff(array $keys, string $visibility = User::COST_REAL, string $email = 'till@example.com'): User
    {
        $user = User::create([
            'name' => 'Till', 'email' => $email,
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
            'cost_visibility' => $visibility, 'cost_markup_percent' => 0,
        ]);

        $user->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id')->all());

        return $user;
    }

    /**
     * ⚠️ A reader kept out of a screen gets no line for it either.
     *
     * The tile already withholds the figure. Before this test the line was free
     * to draw the same information as a shape.
     */
    public function test_a_reader_without_the_permission_gets_no_line_for_it(): void
    {
        $this->seed();

        $page = $this->actingAs($this->staff(['dashboard.view', 'sales.view']))->get(route('dashboard'))->assertOk();

        // One line — sales — and nothing for purchases, expenses or the shelf.
        $this->assertSame(
            1,
            substr_count($page->getContent(), 'class="app-spark"'),
            'A tile whose figure is withheld drew its shape anyway.',
        );
    }

    /**
     * ⚠️ And a reader shown no cost gets no shelf line, for the same reason.
     *
     * Section 2 lets a shop hide cost from a counter assistant. `cost_seen()`
     * returns null for them, and a line drawn from nulls would be a picture
     * made out of the figures being hidden.
     */
    public function test_the_stock_line_follows_the_cost_setting_not_only_the_permission(): void
    {
        $this->seed();

        /*
         * A NON-admin: Section 2 says an admin always sees the real cost and
         * cannot be restricted, so setting the column on one changes nothing.
         * The first version of this test did exactly that and failed for the
         * right reason — it was testing the wrong person.
         */
        $keys = ['dashboard.view', 'sales.view', 'reports.view'];

        $shown = $this->staff($keys, User::COST_REAL, 'sees@example.com');
        $hidden = $this->staff($keys, User::COST_HIDDEN, 'blind@example.com');

        $withCost = substr_count(
            $this->actingAs($shown)->get(route('dashboard'))->getContent(), 'class="app-spark"'
        );
        $withoutCost = substr_count(
            $this->actingAs($hidden)->get(route('dashboard'))->getContent(), 'class="app-spark"'
        );

        $this->assertGreaterThan(0, $withCost);
        $this->assertLessThan($withCost, $withoutCost,
            'Hiding cost left the shelf drawn from the very figures being hidden.');
    }

    /**
     * Each tile in its measure's colour — asked for by Soran, 2026-09-12.
     *
     * The tones are the ones the trend chart below already uses, so the blue
     * line in the tile and the blue line in the chart are the same measure. The
     * shelf takes none: it is a level, and it wears the level's neutral for the
     * same reason the band below does.
     */
    public function test_each_tile_carries_its_measures_tone(): void
    {
        $this->seed();

        $cards = $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
            ->get(route('dashboard'))->assertOk()->viewData('cards');

        $this->assertSame(
            [1, 2, 4, null],
            array_map(fn ($card) => $card['tone'] ?? null, $cards),
            'Sales, purchases, expenses, then the shelf — which takes the neutral.',
        );

        // And the tone reaches the markup, where the stylesheet can find it.
        $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
            ->get(route('dashboard'))
            ->assertSee('data-tone="1"', false)
            ->assertSee('data-tone="4"', false);
    }

    /**
     * ⚠️ A level is not drawn from zero, and this is the whole reason the
     * shelf's tile looked like a solid black block.
     *
     * A shop whose stock is worth ninety million every day for a month has a
     * real shape — it steps up on the day a delivery lands. Drawn from zero,
     * every one of those readings is in the top two percent of the box and the
     * shape is a straight line with the tile filled in underneath it.
     */
    public function test_a_level_is_drawn_over_its_own_range_and_a_flow_is_not(): void
    {
        $steady = [90_000_000, 90_100_000, 90_050_000, 90_200_000];

        $this->assertGreaterThan(
            600,
            $this->spread(Blade::render('<x-chart.spark :values="$v" level />', ['v' => $steady])),
            'A level was flattened against the top of the tile — the solid-block bug.',
        );

        $this->assertLessThan(
            100,
            $this->spread(Blade::render('<x-chart.spark :values="$v" />', ['v' => $steady])),
            'A flow stopped being drawn from zero, which is the opposite mistake.',
        );
    }

    /** A perfectly unchanging level is a flat line across the middle, not at an edge. */
    public function test_an_unchanging_level_sits_in_the_middle(): void
    {
        $drawn = Blade::render('<x-chart.spark :values="$v" level />', ['v' => [5_000_000, 5_000_000, 5_000_000]]);

        foreach ($this->heights($drawn) as $y) {
            $this->assertGreaterThan(300, $y);
            $this->assertLessThan(700, $y);
        }
    }

    /**
     * The note rides on the label's line — moved there by Soran, 2026-09-12.
     *
     * It used to sit between the figure and the chart, which pushed the chart
     * into the card's bottom edge and left the top corner empty.
     */
    public function test_the_note_is_on_the_labels_line_and_not_under_the_figure(): void
    {
        $this->seed();

        $body = $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
            ->get(route('dashboard'))->assertOk()->getContent();

        $note = strpos($body, __('At FIFO cost'));
        $label = strpos($body, __('Stock value'));
        $figure = strpos($body, 'fs-4 fw-semibold money', (int) $label);

        $this->assertNotFalse($note);
        $this->assertGreaterThan($label, $note, 'The note came before its own label.');
        $this->assertLessThan($figure, $note, 'The note is still sitting under the figure.');
    }

    /** How much of the tile's height a drawing actually uses, in viewBox units. */
    private function spread(string $drawn): float
    {
        $ys = $this->heights($drawn);

        return max($ys) - min($ys);
    }

    /**
     * Where the LINE sits, point by point.
     *
     * The line only — the fill beneath it is a closed shape that always runs
     * down to the baseline, so reading both together says every drawing uses
     * the whole tile, which is the one thing being asked about here.
     *
     * @return list<float>
     */
    private function heights(string $drawn): array
    {
        preg_match('/<path d="([^"]+)" fill="none" class="app-spark-line"/', $drawn, $line);

        $this->assertNotEmpty($line, 'No line was drawn at all.');

        preg_match_all('/[ML]\s*[\d.-]+\s+([\d.-]+)/', $line[1], $found);

        $this->assertNotEmpty($found[1], 'The line was drawn with no points in it.');

        return array_map('floatval', $found[1]);
    }
}
