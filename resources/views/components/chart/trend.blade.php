{{--
    Several readings across the same days: the flows on one scale, and — when
    there is one — a level drawn beneath them on a scale of its own.

    This is the shape Soran chose out of four. The point of it is what it
    refuses to do: a chart with two y-axes, one down each side, lets whoever
    drew it slide the lines until they cross wherever looks good, and the
    crossing means nothing. Money that moved on a day and what the shelf was
    worth at closing are thirty times apart here, so they get two drawings that
    share a date axis rather than one drawing with two rulers. The band is
    labelled with its own range, because a reader told the axis starts at 86M is
    informed and one who is not told is misled.

    Written by the server as SVG, and complete before any script runs — this
    page gets printed, and a canvas chart prints as an empty box. The JavaScript
    adds the crosshair and the legend switches on top of a drawing that is
    already there; with it turned off, the chart is still the chart.

    Always left-to-right, in every language. A time axis is a number line, and
    mirroring it puts last week to the right of this week for three of the four
    languages. The title, legend and footer around it mirror with the page.

    Straight segments, never curves. A curve through daily takings invents
    readings nobody took — it bulges above the best day and dips below the worst.
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'labels',            // list<string>  — one short date per day, in order
    'notes' => [],       // list<string>  — the weekday, for the tooltip
    'series',            // list of ['name', 'short'?, 'tone' => 1|2|3, 'values' => list<int>, 'unit'? => 'money'|'count']
    // A reading that does not belong on the flows' scale, drawn as a band
    // beneath them with a scale and a label of its own. Either because it is a
    // level rather than a flow (stock value, which carries in from yesterday
    // and never goes near zero) or because it is not even the same unit (a
    // product's takings beside its units — six and 180,000 are not comparable
    // quantities). Both cases are the same mistake if drawn on one axis.
    'level' => null,     // ['name', 'values' => list<int>, 'unit'? => 'money'|'count']
    'height' => 240,
    'bandHeight' => 92,
    'empty' => null,
])

@php
    use App\Support\Chart;

    $count = count($labels);

    // Two formats on purpose. The axis and the band's range are read at a
    // glance and need to be short; the crosshair is read deliberately and gives
    // the figure in full.
    $figure = fn ($value, $unit) => $unit === 'count'
        ? number_format((int) $value)
        : money($value, false);

    $brief = fn ($value, $unit) => $unit === 'count'
        ? number_format((int) $value)
        : money_short($value);

    // One maximum across every flow, because that is what makes them
    // comparable. Each with its own would be several charts drawn on top of one
    // another, and where they crossed would mean nothing.
    $max = Chart::sharedMax(array_map(fn ($s) => $s['values'], $series));

    // Four bands unless the readings are whole things. Ten units across four
    // gridlines labels the line at 2.5 as "3" — a small lie told once per
    // gridline — so a count takes the first band count that divides it evenly.
    $bands = 4;

    if (($series[0]['unit'] ?? 'money') === 'count' && $max > 0) {
        foreach ([4, 2, 5, 3, 1] as $candidate) {
            if ($max % $candidate === 0) {
                $bands = $candidate;

                break;
            }
        }
    }

    $ticks = Chart::ticks($max, $bands);

    $plotted = [];

    foreach ($series as $index => $s) {
        $fractions = Chart::fractions($s['values'], $max);

        $plotted[] = [
            'key' => 'series-'.$index,
            'name' => $s['name'],
            'short' => $s['short'] ?? $s['name'],
            'tone' => $s['tone'] ?? ($index + 1),
            'unit' => $s['unit'] ?? 'money',
            'fractions' => $fractions,
            'points' => Chart::points($s['values'], 1000, 1000, $max),
            'values' => $s['values'],
        ];
    }

    // The end labels are how a reader tells the lines apart without hunting
    // between the plot and a legend box — and the palette check flags the green
    // as low-contrast on a light surface, where a visible label is the relief
    // it requires. So they are not decoration; they are what makes the third
    // line legal. Nudged apart where two lines finish together.
    $ends = [];

    foreach ($plotted as $line) {
        $ends[] = ['key' => $line['key'], 'tone' => $line['tone'],
                   'short' => $line['short'], 'at' => end($line['fractions'])];
    }

    usort($ends, fn ($a, $b) => $b['at'] <=> $a['at']);

    $gap = 16 / max(1, $height);

    for ($i = 1; $i < count($ends); $i++) {
        if ($ends[$i - 1]['at'] - $ends[$i]['at'] < $gap) {
            $ends[$i]['at'] = $ends[$i - 1]['at'] - $gap;
        }
    }

    // On a quiet day every line finishes near the baseline, and pushing them
    // apart downwards puts them below the plot — where clamping stacks them
    // right back on top of one another, which is the fault this is here to
    // prevent. So the whole set lifts instead.
    $lowest = $ends === [] ? 0 : min(array_column($ends, 'at'));

    if ($lowest < 0) {
        foreach ($ends as $i => $end) {
            $ends[$i]['at'] = $end['at'] - $lowest;
        }
    }

    $axis = Chart::axis($labels, 7);

    // The level, if there is one. Its own window rather than zero: stock value
    // sits at eighty-odd million and moves by four across a month, so drawn
    // from zero it is a straight line — true, and useless.
    $band = null;

    if ($level !== null && $count > 1) {
        [$floor, $ceiling] = Chart::window($level['values']);
        $within = Chart::within($level['values'], $floor, $ceiling);

        $band = [
            'name' => $level['name'],
            'unit' => $level['unit'] ?? 'money',
            'floor' => $floor,
            'ceiling' => $ceiling,
            'values' => $level['values'],
            'points' => array_map(
                fn ($f, $i) => ['x' => round($i * 1000 / max(1, $count - 1), 2), 'y' => round(1000 * (1 - $f), 2)],
                $within,
                array_keys($within),
            ),
            'fractions' => $within,
        ];
    }

    // What the crosshair reads out. The figures are formatted here rather than
    // in the browser: the money format, the digits and the currency word are all
    // the reader's own, and rebuilding them in JavaScript would ship one
    // language's punctuation to all four.
    $payload = [
        'labels' => array_values($labels),
        'notes' => array_values($notes),
        'series' => array_map(fn ($line) => [
            'name' => $line['name'],
            'key' => $line['key'],
            'tone' => $line['tone'],
            'at' => array_map(fn ($f) => round($f, 4), $line['fractions']),
            'text' => array_map(fn ($v) => $figure($v, $line['unit']), $line['values']),
        ], $plotted),
        'level' => $band === null ? null : [
            'name' => $band['name'],
            'key' => 'level',
            'tone' => 0,
            'at' => array_map(fn ($f) => round($f, 4), $band['fractions']),
            'text' => array_map(fn ($v) => $figure($v, $band['unit']), $band['values']),
        ],
    ];
@endphp

<div class="card h-100">
    <div class="card-body">
        @isset($title)
            <h2 class="h6 {{ $subtitle ? 'mb-0' : 'mb-3' }}">{{ $title }}</h2>
        @endisset

        @isset($subtitle)
            <div class="small text-secondary mb-3">{{ $subtitle }}</div>
        @endisset

        @if($count < 2)
            <p class="text-secondary small mb-0">
                {{ $empty ?? __('Not enough days in this period to draw a trend.') }}
            </p>
        @else
            {{-- The legend is plain text until the script upgrades it. Rendering
                 it as buttons that do nothing would be a promise the printed
                 page cannot keep. --}}
            <div class="app-trend-legend small">
                @foreach($plotted as $line)
                    <span class="app-trend-chip" data-series="{{ $line['key'] }}">
                        <span class="app-trend-swatch" data-tone="{{ $line['tone'] }}"></span>{{ $line['name'] }}
                    </span>
                @endforeach

                @isset($band)
                    <span class="app-trend-chip" data-series="level">
                        <span class="app-trend-swatch" data-tone="0"></span>{{ $band['name'] }}
                    </span>
                @endisset
            </div>

            <div class="app-trend" dir="ltr" data-trend="{{ json_encode($payload, JSON_UNESCAPED_UNICODE) }}">
                {{-- The value axis, laid out in HTML beside the drawing rather
                     than inside it: the SVG is stretched to fit its box, which
                     would stretch any text in it out of shape. --}}
                <div class="app-trend-axis" style="--app-trend-height: {{ $height }}px">
                    @foreach($ticks as $tick)
                        <span style="bottom: {{ $max > 0 ? round($tick / $max * 100, 2) : 0 }}%">
                            {{ $brief($tick, $plotted[0]['unit'] ?? 'money') }}
                        </span>
                    @endforeach
                </div>

                <div class="app-trend-plot" style="--app-trend-height: {{ $height }}px" data-plot="flows">
                    <svg viewBox="0 0 1000 1000" preserveAspectRatio="none" role="img"
                         class="app-trend-svg" aria-label="{{ $title ?? __('Trend') }}">
                        @foreach($ticks as $tick)
                            @php($y = $max > 0 ? round(1000 * (1 - $tick / $max), 2) : 1000)
                            <line x1="0" x2="1000" y1="{{ $y }}" y2="{{ $y }}" class="app-chart-grid" />
                        @endforeach

                        @foreach($plotted as $line)
                            <path d="{{ Chart::path($line['points']) }}" fill="none"
                                  class="app-trend-line" data-tone="{{ $line['tone'] }}"
                                  data-series="{{ $line['key'] }}" />
                        @endforeach
                    </svg>
                </div>

                <div class="app-trend-ends" style="--app-trend-height: {{ $height }}px">
                    @foreach($ends as $end)
                        <span data-tone="{{ $end['tone'] }}" data-series="{{ $end['key'] }}"
                              style="bottom: {{ round(max(0, min(1, $end['at'])) * 100, 2) }}%">{{ $end['short'] }}</span>
                    @endforeach
                </div>

                @isset($band)
                    {{-- Said out loud, because it is the one thing a reader could
                         get wrong: this band is not on the scale above it. --}}
                    <div class="app-trend-bandname small" data-series="level">
                        <span class="app-trend-swatch" data-tone="0"></span>
                        <span class="fw-semibold">{{ $band['name'] }}</span>
                        <span class="text-secondary">
                            {{ __('own scale, :low to :high', [
                                'low' => $brief($band['floor'], $band['unit']),
                                'high' => $brief($band['ceiling'], $band['unit']),
                            ]) }}
                        </span>
                    </div>

                    <div class="app-trend-axis" style="--app-trend-height: {{ $bandHeight }}px">
                        <span style="bottom: 100%">{{ $brief($band['ceiling'], $band['unit']) }}</span>
                        <span style="bottom: 0">{{ $brief($band['floor'], $band['unit']) }}</span>
                    </div>

                    <div class="app-trend-plot" style="--app-trend-height: {{ $bandHeight }}px" data-plot="level">
                        <svg viewBox="0 0 1000 1000" preserveAspectRatio="none" role="img"
                             class="app-trend-svg" aria-label="{{ $band['name'] }}">
                            <path d="{{ Chart::areaPath($band['points'], 1000) }}" class="app-trend-band" />
                            <path d="{{ Chart::path($band['points']) }}" fill="none"
                                  class="app-trend-line" data-tone="0" data-series="level" />
                        </svg>
                    </div>

                    <div></div>
                @endisset

                <div></div>

                {{-- The dates, each placed at the fraction of the plot it belongs
                     above rather than spaced evenly — the labels are thinned, and
                     spacing the survivors evenly slides every one off its own day. --}}
                <div class="app-trend-dates small">
                    @foreach($axis as $mark)
                        <span style="@if($mark['at'] <= 0) left: 0
                                     @elseif($mark['at'] >= 1) right: 0
                                     @else left: {{ $mark['at'] * 100 }}%; transform: translateX(-50%)
                                     @endif">{{ $mark['label'] }}</span>
                    @endforeach
                </div>

                <div></div>
            </div>

            @isset($slot)
                <div class="small text-secondary mt-2">{{ $slot }}</div>
            @endisset
        @endif
    </div>
</div>
