<?php

namespace Tests\Feature;

use App\Support\Chart;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The arithmetic behind the charts on the reports page.
 *
 * Everything in App\Support\Chart is pure — numbers in, numbers out — which is
 * the whole reason it was pulled out of the templates. A gridline landing on
 * 47,300 instead of 50,000, or an axis whose last date is missing, is the kind
 * of fault that looks like a rendering quirk and is really a sum.
 */
class ChartTest extends TestCase
{
    #[Test]
    public function it_rounds_a_maximum_up_to_a_number_a_person_would_write(): void
    {
        $this->assertSame(50_000, Chart::niceMax(47_300));
        $this->assertSame(10_000, Chart::niceMax(9_001));
        $this->assertSame(100, Chart::niceMax(100));
        $this->assertSame(1_250_000, Chart::niceMax(1_200_001));
    }

    #[Test]
    public function an_empty_period_has_no_axis_to_speak_of(): void
    {
        // A shop that sold nothing draws a baseline and nothing else. Inventing
        // a scale for it would put a gridline at "25,000" on a chart whose every
        // reading is zero.
        $this->assertSame(0, Chart::niceMax(0));
        $this->assertSame(0, Chart::niceMax(-5));
        $this->assertSame([0], Chart::ticks(0));
        $this->assertSame([0.0, 0.0], Chart::fractions([0, 0]));
    }

    #[Test]
    public function the_gridlines_run_from_zero_to_the_top_inclusive(): void
    {
        $this->assertSame([0, 12_500, 25_000, 37_500, 50_000], Chart::ticks(50_000));
        $this->assertSame([0, 50_000], Chart::ticks(50_000, 1));
    }

    #[Test]
    public function a_value_above_the_stated_maximum_is_held_at_the_top(): void
    {
        // The bars share one maximum across the whole chart. A row that somehow
        // exceeds it must stop at the end of its track rather than run out of
        // the card.
        $this->assertSame([1.0, 0.5], Chart::fractions([200, 50], 100));

        // And a net-negative day sits on the baseline. This chart has one axis,
        // running upwards from zero.
        $this->assertSame([0.0, 1.0], Chart::fractions([-30, 100], 100));
    }

    #[Test]
    public function a_single_reading_becomes_a_point_in_the_middle(): void
    {
        // Not a degenerate path pinned to the left edge, which reads as a fault.
        $this->assertSame(
            [['x' => 320.0, 'y' => 0.0]],
            Chart::points([100], 640, 160, 100),
        );

        $this->assertSame([], Chart::points([], 640, 160));
    }

    #[Test]
    public function the_points_are_evenly_spaced_from_edge_to_edge(): void
    {
        $points = Chart::points([0, 50, 100], 640, 160, 100);

        $this->assertSame(0.0, $points[0]['x']);
        $this->assertSame(320.0, $points[1]['x']);
        $this->assertSame(640.0, $points[2]['x']);

        // y is measured downwards, so the largest value sits nearest the top.
        $this->assertSame(160.0, $points[0]['y']);
        $this->assertSame(80.0, $points[1]['y']);
        $this->assertSame(0.0, $points[2]['y']);
    }

    #[Test]
    public function the_path_is_straight_segments_and_the_area_closes_to_the_baseline(): void
    {
        $points = Chart::points([0, 100], 100, 40, 100);

        // No curve commands: a curve through daily takings invents readings
        // nobody took.
        $this->assertSame('M0 40 L100 0', Chart::path($points));
        $this->assertSame('M0 40 L100 0 L100 40 L0 40 Z', Chart::areaPath($points, 40));

        $this->assertSame('', Chart::path([]));
        $this->assertSame('', Chart::areaPath([], 40));
    }

    #[Test]
    public function several_series_share_one_maximum(): void
    {
        // A chart whose lines each have their own scale is not one chart, it is
        // several drawn on top of one another, and where they cross means
        // nothing.
        $this->assertSame(
            50_000,
            Chart::sharedMax(['sales' => [10_000, 4_000], 'purchases' => [47_300, 0]]),
        );

        $this->assertSame(0, Chart::sharedMax(['a' => [0, 0], 'b' => []]));
        $this->assertSame(0, Chart::sharedMax([]));
    }

    #[Test]
    public function a_level_gets_a_window_around_where_it_actually_sits(): void
    {
        // Stock value sits at eighty-odd million and moves by four across a
        // month. Drawn from zero it is a straight line — true, and useless.
        [$floor, $ceiling] = Chart::window([87_882_112, 92_086_290]);

        $this->assertLessThan(87_882_112, $floor);
        $this->assertGreaterThan(92_086_290, $ceiling);

        // And both ends are round, because those two figures are printed beside
        // the band and read as its range. A label saying "87 M" for a line
        // drawn at 86,831,067 is a rounding presented as a reading.
        $this->assertSame(0, $floor % 1_000_000);
        $this->assertSame(0, $ceiling % 1_000_000);
    }

    #[Test]
    public function a_level_that_never_moved_still_gets_a_window(): void
    {
        // A shop that bought nothing and sold nothing all month has a flat
        // shelf. Zero span would divide by zero and draw nothing at all.
        [$floor, $ceiling] = Chart::window([5_000_000, 5_000_000]);

        $this->assertLessThan(5_000_000, $floor);
        $this->assertGreaterThan(5_000_000, $ceiling);

        // Nothing to draw is not a window at all.
        $this->assertSame([0, 0], Chart::window([]));
    }

    #[Test]
    public function a_reading_is_placed_between_the_floor_and_the_ceiling(): void
    {
        $this->assertSame([0.0, 0.5, 1.0], Chart::within([100, 150, 200], 100, 200));

        // Outside the window is held at its edge rather than drawn out of the
        // card.
        $this->assertSame([0.0, 1.0], Chart::within([50, 500], 100, 200));

        // A window with no span cannot place anything, and says so rather than
        // dividing by zero.
        $this->assertSame([0.0, 0.0], Chart::within([100, 100], 100, 100));
    }

    #[Test]
    public function a_short_axis_keeps_every_label(): void
    {
        $labels = ['1/9', '2/9', '3/9'];

        $this->assertSame($labels, Chart::thinLabels($labels, 7));
    }

    #[Test]
    public function a_long_axis_is_thinned_but_always_keeps_the_last_date(): void
    {
        $labels = array_map(fn (int $day) => "$day/9", range(1, 10));

        $thinned = Chart::thinLabels($labels, 4);

        $this->assertSame(
            ['1/9', null, null, '4/9', null, null, '7/9', null, null, '10/9'],
            $thinned,
        );

        // The right-hand end is where a reader looks first, and an axis whose
        // final tick is blank looks broken.
        $this->assertNotNull($thinned[count($thinned) - 1]);
    }

    #[Test]
    public function the_label_before_the_last_steps_aside_rather_than_collide_with_it(): void
    {
        // Ten days kept every second land on the 1st, 3rd, 5th, 7th and 9th —
        // and then the tenth is forced in beside the ninth. On a wide screen
        // the two merely touched; at phone width they ran together and read as
        // a single wrong date.
        $labels = array_map(fn (int $day) => "$day/9", range(1, 10));

        $this->assertSame(
            ['1/9', null, '3/9', null, '5/9', null, '7/9', null, null, '10/9'],
            Chart::thinLabels($labels, 7),
        );
    }

    #[Test]
    public function a_label_that_is_far_enough_from_the_last_one_keeps_its_place(): void
    {
        // Eleven days kept every third land on the 1st, 4th, 7th and 10th, and
        // the eleventh is one step away — near enough to crowd it.
        $eleven = array_map(fn (int $day) => "$day/9", range(1, 11));

        $this->assertNull(Chart::thinLabels($eleven, 4)[9]);

        // Thirteen days kept every fourth land on the 1st, 5th, 9th and 13th:
        // the last is a label in its own right and nothing has to move.
        $thirteen = array_map(fn (int $day) => "$day/9", range(1, 13));
        $kept = array_keys(array_filter(Chart::thinLabels($thirteen, 4), fn ($l) => $l !== null));

        $this->assertSame([0, 4, 8, 12], $kept);
    }

    #[Test]
    public function the_axis_puts_each_label_at_the_fraction_it_belongs_above(): void
    {
        // Spacing the survivors evenly would slide every one of them off its
        // own reading: on this axis the last two sit a ninth apart, not a fifth.
        $axis = Chart::axis(array_map(fn (int $day) => "$day/9", range(1, 10)), 7);

        $this->assertSame(0.0, $axis[0]['at']);
        $this->assertSame('1/9', $axis[0]['label']);

        $last = $axis[count($axis) - 1];
        $this->assertSame(1.0, $last['at']);
        $this->assertSame('10/9', $last['label']);

        // A single reading is drawn in the middle of the plot, so its label
        // goes there too.
        $this->assertSame([['at' => 0.5, 'label' => '1/9']], Chart::axis(['1/9']));
        $this->assertSame([], Chart::axis([]));
    }

    #[Test]
    public function the_last_label_is_kept_even_when_the_spacing_would_have_dropped_it(): void
    {
        // 11 labels kept every 3rd land on the 1st, 4th, 7th and 10th — and
        // would leave the eleventh, the one that matters most, blank.
        $labels = array_map(fn (int $day) => "$day/9", range(1, 11));

        $thinned = Chart::thinLabels($labels, 4);

        $this->assertSame('11/9', $thinned[10]);
        $this->assertNull($thinned[8]);
        $this->assertNull($thinned[9], 'the 10th stepped aside for the 11th');
    }
}
