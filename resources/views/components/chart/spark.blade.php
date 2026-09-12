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

    One neutral colour for every tile, never the measure's colour from the chart
    below — see `.app-spark-line` in app.scss for why that was tried and undone.
--}}
@props([
    'values' => [],
    'height' => 28,
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
    $max = $enough ? max(max($values), 1) : 1;

    // Negative days exist — a day of returns outruns its sales — and drawing
    // from zero would clip them off the bottom without saying so.
    $floor = $enough ? min(0, min($values)) : 0;
    $span = max(1, $max - $floor);

    $points = $enough
        ? array_map(fn ($v, $i) => [
            'x' => round($i * 1000 / (count($values) - 1), 2),
            'y' => round(1000 * (1 - ($v - $floor) / $span), 2),
        ], $values, array_keys($values))
        : [];
@endphp

@if($enough)
    <div class="app-spark" dir="ltr" style="--app-spark-height: {{ $height }}px" aria-hidden="true">
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
