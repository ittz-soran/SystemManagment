{{--
    Reading a screen's figures in another currency — Section 2b.

    ⚠️ **A screen opts into this; nothing is global.** Section 2b keeps the lens
    off the till, and the way that is enforced is that `money()` only converts
    when a screen hands it a currency. A switcher in the topbar would put the
    lens back on the sale screen by accident, which is the one place a wrong
    number costs money in the same minute.

    Buttons rather than a select, so it works with no JavaScript at all — the
    same shape as the trend chart's period switch beside it. It posts a
    preference, so the choice follows the person to their next visit and does
    not follow anybody else.

    Hidden entirely when the shop has only its own currency, because a switch
    with one position is furniture.
--}}
@props(['label' => null])

@php
    use App\Models\Currency;
    use App\Support\Money;

    $base = Money::base();

    $offered = collect(Currency::cached())
        ->filter(fn ($c) => $c->is_active)
        ->sortBy(fn ($c) => $c->code === $base->code ? '' : $c->code)
        ->values();

    $chosen = auth()->user()?->lens()?->code ?? $base->code;
@endphp

@if($offered->count() > 1)
    <form method="POST" action="{{ route('preferences.currency') }}"
          class="d-flex align-items-center gap-2 m-0 no-print">
        @csrf

        @if($label)
            <span class="small text-secondary">{{ $label }}</span>
        @endif

        <div class="btn-group btn-group-sm" role="group"
             aria-label="{{ __('Read these figures in') }}">
            @foreach($offered as $currency)
                <button type="submit" name="display_currency" value="{{ $currency->code }}"
                        class="btn btn-{{ $currency->code === $chosen ? 'secondary' : 'outline-secondary' }} app-code"
                        @if($currency->code === $chosen) aria-current="true" @endif>
                    {{ $currency->code }}
                </button>
            @endforeach
        </div>
    </form>
@endif
