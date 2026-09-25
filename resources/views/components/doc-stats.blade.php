@props([
    /*
     * Four figures, each ['label' => …, 'value' => …, 'note' => …].
     *
     * `value` is already formatted — a count through number_format, money
     * through money() — because only the caller knows which of the two it is
     * and whether the reader is allowed to see it.
     */
    'tiles' => [],

    // Whether the reader narrowed the list. It decides one sentence, and that
    // sentence is the whole reason this component can be trusted.
    'filtered' => false,
])

{{--
    What a shopkeeper opens one of these lists to find out — Soran, 2026-09-25:
    *"make better ui and data statics"*.

    ⚠️ **Every figure is OF THE FILTERED RANGE, and the strip says so.** A
    figure with no scope written beside it is read as a figure about
    everything — which is exactly what produced *"in services total show
    290,000 … in reports show 231,000 !! that is wrong"*, where nothing was
    wrong except that one page counted all time and the other counted a month,
    and neither said which.
--}}
@if(filled($tiles))
    <div class="row g-2 mb-3">
        @foreach($tiles as $tile)
            <div class="col-6 col-lg-3">
                <div class="stat-tile">
                    <span class="stat-tile-label">{{ $tile['label'] }}</span>
                    <span class="stat-tile-value">{{ $tile['value'] }}</span>
                    @isset($tile['note'])
                        <span class="stat-tile-note">{{ $tile['note'] }}</span>
                    @endisset
                </div>
            </div>
        @endforeach
    </div>

    <p class="small text-secondary mb-3">
        <i class="bi bi-funnel me-1"></i>
        {{ $filtered
            ? __('These four count only what the filter below is showing.')
            : __('These four count everything on this list. Narrow it below and they follow.') }}
    </p>
@endif
