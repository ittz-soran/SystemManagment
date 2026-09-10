<?php

namespace App\Support;

/**
 * The arithmetic behind a chart, kept away from the drawing.
 *
 * The charts in this shop are inline SVG written by the server. No library, for
 * reasons that are this project's rather than fashion's: these installs sit on
 * shared hosting behind a slow connection, the app self-hosts every font and
 * icon rather than reaching for a CDN, and — the one that settles it — a report
 * gets printed. A canvas chart prints as an empty box on half the browsers that
 * will ever open this; an SVG prints as a chart.
 *
 * Which leaves the sums, and sums are worth testing. Everything here is pure:
 * numbers in, numbers out, no Blade and no database. The templates that use it
 * only place what it returns.
 */
final class Chart
{
    /**
     * Round a maximum up to a number a person would write on an axis.
     *
     * 47,300 becomes 50,000 rather than 47,300, so the gridlines land on values
     * a reader can hold in their head. Zero stays zero: an empty chart draws a
     * baseline and nothing else, which is the honest picture of a shop that
     * sold nothing.
     */
    public static function niceMax(int $value): int
    {
        if ($value <= 0) {
            return 0;
        }

        $magnitude = 10 ** max(0, strlen((string) $value) - 2);

        foreach ([1, 1.25, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            $candidate = (int) ceil($step * $magnitude * 10);

            if ($candidate >= $value) {
                return $candidate;
            }
        }

        return (int) (ceil($value / $magnitude) * $magnitude);
    }

    /**
     * Gridline values from zero to the top, inclusive.
     *
     * Four bands is the most a small chart can carry before the lines start
     * competing with the data for attention.
     *
     * @return list<int>
     */
    public static function ticks(int $max, int $bands = 4): array
    {
        if ($max <= 0 || $bands < 1) {
            return [0];
        }

        return array_map(
            fn (int $band) => (int) round($max * $band / $bands),
            range(0, $bands),
        );
    }

    /**
     * Where each value sits, as a fraction of the plot's height.
     *
     * Returned as a fraction rather than a pixel so the same numbers serve a
     * chart of any size, and so a test can read them without knowing the
     * viewBox.
     *
     * @param  list<int|float>  $values
     * @return list<float>
     */
    public static function fractions(array $values, ?int $max = null): array
    {
        $max ??= self::niceMax((int) ceil(max([0, ...$values])));

        if ($max <= 0) {
            return array_map(fn () => 0.0, $values);
        }

        return array_map(fn ($value) => max(0.0, min(1.0, $value / $max)), $values);
    }

    /**
     * The points of a line across a plot, evenly spaced left to right.
     *
     * A single reading has no line to draw, so it becomes a point in the middle
     * rather than a degenerate path at the left edge.
     *
     * @param  list<int|float>  $values
     * @return list<array{x: float, y: float}>
     */
    public static function points(array $values, float $width, float $height, ?int $max = null): array
    {
        $count = count($values);

        if ($count === 0) {
            return [];
        }

        $fractions = self::fractions($values, $max);

        if ($count === 1) {
            return [['x' => round($width / 2, 2), 'y' => round($height * (1 - $fractions[0]), 2)]];
        }

        $step = $width / ($count - 1);

        return array_map(
            fn (int $index) => [
                'x' => round($index * $step, 2),
                'y' => round($height * (1 - $fractions[$index]), 2),
            ],
            range(0, $count - 1),
        );
    }

    /**
     * An SVG path through those points.
     *
     * Straight segments, not curves. A curve through daily takings invents
     * readings that were never taken — it bulges above the highest day and dips
     * below the lowest — and a shopkeeper reading Tuesday off a chart should get
     * Tuesday's number.
     *
     * @param  list<array{x: float, y: float}>  $points
     */
    public static function path(array $points): string
    {
        if ($points === []) {
            return '';
        }

        $commands = array_map(
            fn (array $point, int $index) => ($index === 0 ? 'M' : 'L').$point['x'].' '.$point['y'],
            $points,
            array_keys($points),
        );

        return implode(' ', $commands);
    }

    /**
     * The same path closed down to the baseline, for the wash under the line.
     *
     * @param  list<array{x: float, y: float}>  $points
     */
    public static function areaPath(array $points, float $height): string
    {
        if ($points === []) {
            return '';
        }

        $first = $points[0];
        $last = $points[count($points) - 1];

        return self::path($points)." L{$last['x']} {$height} L{$first['x']} {$height} Z";
    }

    /**
     * One maximum for several series, so they can be read against each other.
     *
     * A chart whose lines each have their own scale is not one chart, it is
     * several drawn on top of one another, and where they cross means nothing.
     *
     * @param  array<int|string, list<int|float>>  $series
     */
    public static function sharedMax(array $series): int
    {
        $highest = 0;

        foreach ($series as $values) {
            foreach ($values as $value) {
                $highest = max($highest, (int) ceil($value));
            }
        }

        return self::niceMax($highest);
    }

    /**
     * The floor and ceiling for a level — a reading that is never near zero.
     *
     * Stock value sits at eighty-odd million and moves by four across a month.
     * Drawn from zero it is a straight line: true, and useless. A level gets a
     * window around the range it actually occupies, which is why it is drawn
     * apart from the flows and labelled with its own scale rather than sharing
     * theirs — a reader who is told the axis starts at 86M is informed, one who
     * is not told is misled.
     *
     * @param  list<int|float>  $values
     * @return array{0: int, 1: int}
     */
    public static function window(array $values, float $pad = 0.25): array
    {
        if ($values === []) {
            return [0, 0];
        }

        $low = (int) floor(min($values));
        $high = (int) ceil(max($values));

        // A level that never moved still needs a window to sit in the middle of.
        $span = ($high - $low) ?: max(1, (int) abs($high));
        $margin = (int) ceil($span * $pad);

        // Snapped to a round step, because the two figures printed beside the
        // band are read as the band's range. An axis labelled "87 M" whose line
        // is really at 86,831,067 is a rounding presented as a reading.
        $step = 10 ** max(0, strlen((string) $span) - 1);

        return [
            max(0, (int) (floor(($low - $margin) / $step) * $step)),
            (int) (ceil(($high + $margin) / $step) * $step),
        ];
    }

    /**
     * Where each value sits between a floor and a ceiling, as a fraction.
     *
     * `fractions()` measures from zero, which is right for money that moved and
     * wrong for a level in its own window.
     *
     * @param  list<int|float>  $values
     * @return list<float>
     */
    public static function within(array $values, int $floor, int $max): array
    {
        $span = $max - $floor;

        if ($span <= 0) {
            return array_map(fn () => 0.0, $values);
        }

        return array_map(
            fn ($value) => max(0.0, min(1.0, ($value - $floor) / $span)),
            $values,
        );
    }

    /**
     * The labels an axis actually shows, each with the fraction across the plot
     * it belongs above.
     *
     * The fraction matters: the labels are thinned, so spacing what survives
     * evenly would slide every one of them off its own reading — the last two
     * dates on a ten-day chart sit a tenth apart, not a sixth.
     *
     * @param  list<string>  $labels
     * @return list<array{at: float, label: string}>
     */
    public static function axis(array $labels, int $keep = 7): array
    {
        $count = count($labels);

        if ($count === 0) {
            return [];
        }

        if ($count === 1) {
            return [['at' => 0.5, 'label' => $labels[0]]];
        }

        $shown = [];

        foreach (self::thinLabels($labels, $keep) as $index => $label) {
            if ($label !== null) {
                $shown[] = ['at' => round($index / ($count - 1), 4), 'label' => $label];
            }
        }

        return $shown;
    }

    /**
     * Every nth label, so an axis of ninety days does not become a smear.
     *
     * The last one is always kept: the right-hand end is where a reader looks
     * first, and an axis whose final tick is unlabelled looks broken. When
     * keeping it would crowd the one before — a ten-day chart lands a label on
     * the ninth and then forces one on the tenth — that neighbour steps aside
     * instead. On a wide screen the two merely touched; on a phone they ran
     * into each other and read as a single wrong date.
     *
     * @param  list<string>  $labels
     * @return list<string|null>
     */
    public static function thinLabels(array $labels, int $keep = 7): array
    {
        $count = count($labels);

        if ($count <= $keep || $keep < 1) {
            return $labels;
        }

        $every = (int) ceil($count / $keep);
        $last = $count - 1;

        $kept = [];

        for ($index = 0; $index < $count; $index += $every) {
            $kept[$index] = true;
        }

        if (! isset($kept[$last])) {
            $previous = intdiv($last, $every) * $every;

            if ($last - $previous <= intdiv($every + 1, 2)) {
                unset($kept[$previous]);
            }

            $kept[$last] = true;
        }

        return array_map(
            fn (int $index) => isset($kept[$index]) ? $labels[$index] : null,
            range(0, $last),
        );
    }
}
