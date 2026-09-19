{{--
    Language and currency in one menu — Soran, 2026-09-18.

    *"move read in/type in currency to near languages, i send an small simple
    design, but have flag and select both languages and currency"*.

    Two columns on a screen with room, two stacked sections on a phone: at
    390px two columns of names in four scripts do not fit, and a currency list
    that wraps mid-word is worse than one below the other.

    ⚠️ **The currency half is the READER'S lens.** It changes what figures are
    shown as, never what is stored, and never the till — §2b. The sentence at
    the foot says both of those things, because a control in the topbar looks
    like it applies to the screen underneath it and on the sale screen it
    deliberately does not.
--}}
@php
    use App\Http\Middleware\SetUserPreferences;
    use App\Models\Currency;
    use App\Support\Flags;
    use App\Support\Money;

    $base = Money::base();

    $currencies = collect(Currency::cached())
        ->filter(fn ($currency) => $currency->is_active)
        ->sortBy(fn ($currency) => $currency->code === $base->code ? '' : $currency->code)
        ->values();

    $lens = auth()->user()?->lens()?->code ?? $base->code;

    $languageFlag = Flags::forLanguage($currentLanguage);
    $currencyFlag = Flags::forCurrency($lens);
@endphp

<div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-1"
            data-bs-toggle="dropdown" data-bs-auto-close="outside"
            aria-label="{{ __('Language and currency') }}">
        @if($languageFlag)
            <img src="{{ asset($languageFlag) }}" alt="" class="app-flag">
        @else
            <i class="bi bi-translate" aria-hidden="true"></i>
        @endif

        <span class="d-none d-md-inline">
            {{ SetUserPreferences::LANGUAGES[$currentLanguage] ?? $currentLanguage }}
        </span>

        @if($currencies->count() > 1)
            <span class="text-secondary d-none d-md-inline" aria-hidden="true">·</span>

            @if($currencyFlag)
                <img src="{{ asset($currencyFlag) }}" alt="" class="app-flag">
            @endif

            <span class="app-code d-none d-md-inline">{{ $lens }}</span>
        @endif
    </button>

    <div class="dropdown-menu dropdown-menu-end p-0 app-prefs-menu">
        <div class="row g-0">
            <div class="col-12 col-sm-6 py-2">
                <div class="app-prefs-title">{{ __('Language') }}</div>

                @foreach(SetUserPreferences::LANGUAGES as $code => $name)
                    @php($flag = Flags::forLanguage($code))
                    <form action="{{ route('preferences.language') }}" method="POST">
                        @csrf
                        <input type="hidden" name="language" value="{{ $code }}">
                        <button type="submit" class="dropdown-item app-prefs-item {{ $currentLanguage === $code ? 'active' : '' }}">
                            @if($flag)
                                <img src="{{ asset($flag) }}" alt="" class="app-flag">
                            @endif
                            <span>{{ $name }}</span>
                        </button>
                    </form>
                @endforeach
            </div>

            {{-- Hidden entirely when the shop keeps only its own money: a
                 switch with one position is furniture. --}}
            @if($currencies->count() > 1)
                <div class="col-12 col-sm-6 py-2 app-prefs-divider">
                    <div class="app-prefs-title">{{ __('Read figures in') }}</div>

                    <form action="{{ route('preferences.currency') }}" method="POST">
                        @csrf

                        @foreach($currencies as $currency)
                            @php($flag = Flags::forCurrency($currency->code))
                            <button type="submit" name="display_currency" value="{{ $currency->code }}"
                                    class="dropdown-item app-prefs-item {{ $currency->code === $lens ? 'active' : '' }}"
                                    @if($currency->code === $lens) aria-current="true" @endif>
                                @if($flag)
                                    <img src="{{ asset($flag) }}" alt="" class="app-flag">
                                @endif
                                <span>{{ $currency->name }}</span>
                                <span class="app-code app-prefs-code">{{ $currency->code }}</span>
                            </button>
                        @endforeach
                    </form>
                </div>
            @endif
        </div>

        {{-- ⚠️ **No sentence about estimates here, and that is not an
             omission.** A footer saying "converted figures are an estimate"
             read well in the mock and was wrong in the building: this menu is
             in the topbar of EVERY screen, so it put estimate wording on pages
             with nothing converted on them — and on the sale screen, where
             §2b forbids it outright and CurrencyLensTest asserts it.

             The disclaimer already exists and belongs where the figures are:
             `<x-lens-note>` sits on each converting page and says it with that
             page's actual rate, which is more use than a line in a menu. --}}
    </div>
</div>
