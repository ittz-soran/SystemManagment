{{--
    The tile's figure, with the shape of the month behind it.

    **Asked for by Soran, 2026-09-12:** *"in dashboard statics can have small one
    line chart"*. A tile says what today was; it cannot say whether today was
    normal, and that is the question somebody standing at the counter has. Four
    hundred pixels of line answers it without them reading a number.

    Deliberately almost nothing: no axis, no gridline, no label, no tooltip. A
    sparkline is a word in a sentence, not a chart — anything that invites
    hovering or measuring belongs on the trend chart below, which exists and is
    better at it. What this says is "rising", "flat", "one big day", and it says
    it in peripheral vision.

    The last point is marked, because that is the figure printed beside it, and
    a reader should be able to see which end of the line is today. On an RTL
    page the drawing still runs left to right: time is a number line, and
    mirroring it would put last week to the right of this week.

    A flat line is drawn deliberately rather than skipped. "Nothing happened for
    four weeks" is a real answer and an empty box is not.

    The colour is the measure's own, the one it wears on the trend chart below —
    so a reader learns the colours once for the whole screen rather than once
    per section. Left off, the line is the neutral the level wears. See
    `.app-spark-line` in app.scss, and the tone table above it.

    ⚠️ A LEVEL is not drawn from zero, and a flow is. A day with no sales really
    is zero and the line should touch the floor; the shelf has never in its life
    been worth nothing, so drawing it from zero puts every reading in the top
    two percent of the box and the tile becomes one solid block with a flat edge
    — which is exactly what it looked like, and it said nothing at all. So a
    level is drawn over its own range instead, which is the same choice the
    trend chart's band makes for the same reading and the same reason.
--}}
@props([
    'values' => [],
    'height' => 28,
    'tone' => null,     // 1|2|3|4 — the measure's tone. Null for the level's neutral.
    'level' => false,   // A reading that carries in from yesterday — see above.
])

@php
    use App\Support\Chart;

    $values = array_values(array_map('intval', $values));

    // Under two points there is no line to draw — one day is a dot, and a dot
    // in a tile is a smudge that means nothing.
    $enough = count($values) > 1;

    // Its own scale, always. These are four different quantities in four
    // different units, and a shared scale would flatten three of them to
    // nothing to make room for the largest.
    $high = $enough ? max($values) : 1;
    $low = $enough ? min($values) : 0;

    /*
     * A flow is drawn from zero, because a day with no sales really is zero and
     * the line should touch the floor. Negative days exist too — a day of
     * returns outruns its sales — and drawing from zero would clip them off the
     * bottom without saying so.
     *
     * A level ignores zero and is drawn over its own range, with a margin at
     * each end so neither the highest nor the lowest reading is sliced in half
     * by the tile's edge. A perfectly unchanging level has no range to speak
     * of, so it borrows a fraction of itself and lands as a flat line across
     * the middle, which is the true answer.
     */
    if ($level) {
        $margin = max(1, (int) ceil(($high - $low ?: abs($high)) * .18));
        $floor = $low - $margin;
        $ceiling = $high + $margin;
    } else {
        $floor = min(0, $low);
        $ceiling = max($high, 1);
    }

    $span = max(1, $ceiling - $floor);

    $points = $enough
        ? array_map(fn ($v, $i) => [
            'x' => round($i * 1000 / (count($values) - 1), 2),
            'y' => round(1000 * (1 - ($v - $floor) / $span), 2),
        ], $values, array_keys($values))
        : [];
@endphp

@if($enough)
    <div class="app-spark" dir="ltr" @if($tone) data-tone="{{ $tone }}" @endif
         style="--app-spark-height: {{ $height }}px" aria-hidden="true">
        <svg viewBox="0 0 1000 1000" preserveAspectRatio="none" class="app-spark-svg" focusable="false">
            <path d="{{ Chart::areaPath($points, 1000) }}" class="app-spark-fill" />
            <path d="{{ Chart::path($points) }}" fill="none" class="app-spark-line" />
        </svg>

        {{-- The last point, as an HTML dot rather than an SVG circle: the plot
             is stretched with preserveAspectRatio="none", which would squash a
             circle into an ellipse. --}}
        <span class="app-spark-now"
              style="inset-inline-start: 100%; bottom: {{ round((end($values) - $floor) / $span * 100, 2) }}%"></span>
    </div>
@endif
