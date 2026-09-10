{{--
    A trend over time: one line, a wash beneath it, and the extremes labelled.

    Inline SVG written by the server. No charting library, for reasons that are
    this project's rather than fashion's: these installs sit on shared hosting
    behind a slow connection, the app self-hosts every font and icon rather than
    reaching for a CDN, and — the one that settles it — reports get printed. A
    canvas chart prints as an empty box in half the browsers that will open
    this; an SVG prints as a chart.

    Always left-to-right, in every language. The keypad made the same choice and
    for the same reason (resources/js — a keypad is not text): a time axis is a
    number line, and mirroring it would put last week to the right of this week
    for three of the four languages. The labels beneath it are the reader's own.

    Straight segments, never curves. A curve through daily takings invents
    readings nobody took — it bulges above the best day and dips below the worst
    — and a shopkeeper reading Tuesday off this should get Tuesday's number.
--}}
@props([
    'title',
    'subtitle' => null,
    'points',        // list of ['label' => string, 'value' => int]
    'empty' => null,
])

@php
    $values = array_map(fn ($point) => $point['value'], $points);
    $labels = array_map(fn ($point) => $point['label'], $points);

    $max = App\Support\Chart::niceMax((int) ceil(max([0, ...$values])));

    // A fixed viewBox with the SVG scaled to its box: the shape is worked out
    // once here and the browser does the fitting, so nothing has to be measured
    // in pixels at render time.
    $width = 640;
    $height = 160;

    $plot = App\Support\Chart::points($values, $width, $height, $max);
    $ticks = App\Support\Chart::ticks($max);
    // Every nth date, so ninety days do not become a smear — each carrying the
    // fraction across the plot it belongs above, because spacing the survivors
    // evenly would slide every one of them off its own reading.
    $axis = App\Support\Chart::axis($labels, 7);

    // Labelled selectively — the best day, and the last. A number on every point
    // is chaos and goes unread.
    $peak = $values === [] ? null : array_search(max($values), $values, true);
    $last = count($values) - 1;

    // A day where more came back than went out is a real reading, and it is
    // drawn sitting on the baseline rather than below it — this chart has one
    // axis running from zero upwards, and dropping a second one below it to
    // catch the two or three days a year that go negative would cost every
    // other day half its height. The footer totals still use the true figures.
@endphp

<div class="card">
    <div class="card-body">
        <h2 class="h6 {{ $subtitle ? 'mb-0' : 'mb-3' }}">{{ $title }}</h2>
        @isset($subtitle)
            <div class="small text-secondary mb-3">{{ $subtitle }}</div>
        @endisset

        @if(count($points) < 2)
            <p class="text-secondary small mb-0">{{ $empty ?? __('Not enough days in this period to draw a trend.') }}</p>
        @else
            <div class="app-chart-line" dir="ltr">
                {{-- Four gridlines that stand for nothing are decoration. The
                     top one is labelled and the rest are quarters of it, which
                     is as much scale as a chart this size can carry without the
                     numbers competing with the line. --}}
                <div class="app-chart-top small text-secondary">{{ money($max, false) }}</div>

                <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img"
                     preserveAspectRatio="none" class="app-chart-svg"
                     aria-label="{{ $title }}">
                    {{-- Gridlines first, so the data is drawn over them. --}}
                    @foreach($ticks as $tick)
                        @php($y = $max > 0 ? round($height * (1 - $tick / $max), 2) : $height)
                        <line x1="0" x2="{{ $width }}" y1="{{ $y }}" y2="{{ $y }}" class="app-chart-grid" />
                    @endforeach

                    <path d="{{ App\Support\Chart::areaPath($plot, $height) }}" class="app-chart-area" />
                    <path d="{{ App\Support\Chart::path($plot) }}" class="app-chart-stroke" />

                    {{-- One dot on the last reading: where the eye goes first. --}}
                    <circle cx="{{ $plot[$last]['x'] }}" cy="{{ $plot[$last]['y'] }}" r="4"
                            class="app-chart-dot" />
                </svg>

                {{-- The axis in HTML, not SVG: the browser places and truncates
                     text properly, and the SVG is stretched to fit its box,
                     which would stretch any text inside it with it. --}}
                <div class="app-chart-axis small text-secondary">
                    @foreach($axis as $mark)
                        {{-- The two ends are pinned rather than centred, so the
                             first and last dates cannot hang off the card. --}}
                        <span style="@if($mark['at'] <= 0) left: 0
                                     @elseif($mark['at'] >= 1) right: 0
                                     @else left: {{ $mark['at'] * 100 }}%; transform: translateX(-50%)
                                     @endif">{{ $mark['label'] }}</span>
                    @endforeach
                </div>
            </div>

            <div class="d-flex flex-wrap gap-3 small text-secondary mt-2">
                <span>{{ __('Best day') }}: <span class="text-body fw-semibold">{{ money(max($values)) }}</span>
                    @if($peak !== false && $peak !== null) ({{ $labels[$peak] }}) @endif</span>
                <span>{{ __('Total') }}: <span class="text-body fw-semibold">{{ money(array_sum($values)) }}</span></span>
            </div>
        @endif
    </div>
</div>
