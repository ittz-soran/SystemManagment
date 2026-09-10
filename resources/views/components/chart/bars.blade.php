{{--
    A ranked comparison: the biggest at the top, one hue, longer is more.

    Horizontal rather than vertical because the labels are product and category
    names — real ones, long ones, in four alphabets — and a vertical chart either
    turns them on their side or cuts them off. Laid out in HTML rather than SVG
    for the same reason: the browser wraps and truncates text far better than a
    hand-placed <text> can, and it does it correctly in RTL without being asked.

    One hue and no legend. There is a single series, so a legend box would only
    restate the heading above it.
--}}
@props([
    'title',
    'rows',          // list of ['label' => string, 'value' => int, 'note' => ?string]
    'empty' => null,
])

@php
    $values = array_map(fn ($row) => $row['value'], $rows);
    $max = App\Support\Chart::niceMax((int) ceil(max([0, ...$values])));
    $fractions = App\Support\Chart::fractions($values, $max);
@endphp

<div class="card h-100">
    <div class="card-body">
        <h2 class="h6 mb-3">{{ $title }}</h2>

        @if($rows === [])
            {{-- Section 9b: an empty table is an instruction, not a blank space. --}}
            <p class="text-secondary small mb-0">{{ $empty ?? __('Nothing in this period.') }}</p>
        @else
            <div class="app-chart-bars">
                @foreach($rows as $index => $row)
                    <div class="app-chart-bar-row">
                        {{-- dir="auto" so the name is cut at its own end: a
                             Latin product name inside a Kurdish page is
                             left-to-right text, and truncating it by the page's
                             direction eats the beginning instead. --}}
                        <div class="app-chart-bar-label small text-truncate" dir="auto"
                             title="{{ $row['label'] }}">
                            {{ $row['label'] }}
                        </div>

                        {{-- The native title is the tooltip: no script to load,
                             and it survives printing and screen readers alike. --}}
                        <div class="app-chart-bar-track"
                             title="{{ $row['label'] }} — {{ money($row['value']) }}">
                            {{-- A hair of width on the smallest rows, so a real
                                 sale never draws as an empty track. --}}
                            <div class="app-chart-bar-fill"
                                 style="width: {{ max(1.5, round($fractions[$index] * 100, 2)) }}%"></div>
                        </div>

                        <div class="app-chart-bar-value small text-secondary" dir="ltr">
                            {{ $row['note'] ?? money($row['value'], false) }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
