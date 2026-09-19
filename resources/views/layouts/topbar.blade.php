{{-- Section 9b: a slim topbar holding global search, language switch, theme
     toggle and the user menu. --}}
<header class="app-topbar bg-body border-bottom px-3 py-2 d-flex align-items-center gap-2 gap-md-3 no-print">
    {{-- The only way to the menu on a phone, where the sidebar is a drawer.
         Above md the sidebar is on the screen already and this would be a
         button that opens what is open. --}}
    <button type="button" class="btn btn-sm btn-outline-secondary d-md-none flex-shrink-0"
            data-bs-toggle="offcanvas" data-bs-target="#app-nav"
            aria-controls="app-nav" aria-label="{{ __('Menu') }}">
        <i class="bi bi-list"></i>
    </button>

    {{-- One box for the whole shop: a product, a person, a document number off a
         printed invoice, or the name of a screen. What it finds is decided by
         the server, which shows a reader only what they may open. --}}
    {{-- On a phone the search is a magnifier until it is wanted — Soran,
         2026-09-19: "search box in top bar for mobile version should just show
         search icon then expand input and hide other elements because on
         mobile can show something at once".

         Below md only: above it the bar has room for the box and everything
         else at once, and hiding a search behind a tap there would be a step
         nobody needs. --}}
    <button type="button" class="btn btn-sm btn-outline-secondary d-md-none flex-shrink-0"
            id="app-search-open" aria-label="{{ __('Search') }}"
            aria-expanded="false" aria-controls="app-search">
        <i class="bi bi-search" aria-hidden="true"></i>
    </button>

    <div class="app-search flex-grow-1 min-w-0 position-relative d-none d-md-block" style="max-width: 30rem">
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-body-tertiary border-end-0">
                <i class="bi bi-search"></i>
            </span>
            <input id="app-search" type="search" class="form-control border-start-0" data-english-digits
                   placeholder="{{ __('Search anything — a product, a name, INV-00005…') }}"
                   aria-label="{{ __('Search') }}" autocomplete="off"
                   role="combobox" aria-expanded="false" aria-controls="app-search-results">
            <span class="input-group-text bg-body-tertiary text-secondary small d-none d-lg-inline">
                {{-- The shortcut, where a keyboard user will look for it. --}}
                Ctrl K
            </span>

            {{-- The way back, on a phone. Without it the only way out of the
                 search is the browser's own back button, which leaves the
                 screen. --}}
            <button type="button" class="btn btn-outline-secondary d-md-none" id="app-search-close"
                    aria-label="{{ __('Close search') }}">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>

        <div id="app-search-results" class="app-search-results dropdown-menu w-100 p-0 overflow-auto"
             role="listbox" aria-label="{{ __('Search') }}"
             data-url="{{ route('search') }}"
             data-empty="{{ __('Nothing found.') }}"></div>
    </div>

    <div class="ms-auto d-flex align-items-center gap-2" id="app-topbar-rest">
        {{-- Whether this screen can still reach the shop's own server.
             Section 9b's rule about saying the truth plainly, applied to the
             one thing a browser hides: a page that has lost the network looks
             exactly like a page that is working until something is saved. At a
             till that difference is a sale.

             Quiet when connected — a green dot nobody has to read — and
             unmissable when not. app.js drives it. --}}
        <span id="app-connection" class="app-connection d-inline-flex align-items-center gap-1 small"
              data-url="{{ url('up') }}"
              data-online="{{ __('Connected') }}"
              data-offline="{{ __('No connection') }}"
              data-offline-banner="{{ __('No connection to the shop’s server. Nothing typed now will be saved — wait for this to clear before ringing up a sale.') }}"
              title="{{ __('Connected') }}">
            <span class="app-connection-dot" aria-hidden="true"></span>
            <span class="app-connection-word d-none"></span>
        </span>

        {{-- The wall clock: the machine's own time, so it agrees with the clock
             on the wall beside it.

             Written in the reader's own language unless they asked otherwise —
             چوارشەممە ٩ی ئەیلول rather than Wed, 09 Sep. The names travel as
             data because the clock is drawn in the browser, and they come from
             __() rather than from Intl: no browser has the Kurdish month names
             Soran uses, and going through __() means translations:check counts
             them.

             Not dir="ltr" any more on the date — the words are the reader's, so
             the line runs the reader's way. The time keeps its own direction:
             1:29:45 is a number and reads left to right in every language. --}}
        @php($clock24 = (bool) (auth()->user()?->clock_24_hour ?? false))
        <div class="app-clock text-end d-none d-md-block lh-sm"
             data-weekdays="{{ json_encode(App\Support\CalendarNames::weekdays(), JSON_UNESCAPED_UNICODE) }}"
             data-months="{{ json_encode(App\Support\CalendarNames::months(), JSON_UNESCAPED_UNICODE) }}"
             data-meridiem="{{ json_encode(App\Support\CalendarNames::meridiem(), JSON_UNESCAPED_UNICODE) }}"
             data-date-format="{{ App\Support\CalendarNames::dateFormat() }}"
             data-time-format="{{ App\Support\CalendarNames::timeFormat() }}"
             data-english="{{ (auth()->user()?->date_language ?? 'interface') === 'english' ? '1' : '' }}"
             data-hour12="{{ $clock24 ? '' : '1' }}">
            <div class="fw-semibold" id="app-clock-time" dir="ltr">&nbsp;</div>
            <div class="text-secondary" id="app-clock-date">&nbsp;</div>
        </div>

        {{--
            Help for whatever screen this is.

            Before the language and theme buttons rather than after, because it
            is the one a lost reader is looking for and the eye stops at the
            first thing in a row. Shown only where there is something to say —
            a button that opens an empty drawer teaches people to stop pressing
            it.

            No permission of its own, deliberately: the reader most likely to
            need help is a new user holding the fewest permissions in the shop.
        --}}
        @if(App\Support\ScreenHelp::has(request()->route()?->getName()))
            <button class="btn btn-sm btn-primary" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#screen-help"
                    aria-controls="screen-help"
                    title="{{ __('Help for this screen') }}"
                    aria-label="{{ __('Help for this screen') }}">
                <i class="bi bi-question-lg" aria-hidden="true"></i>
            </button>
        @endif

        {{-- What has happened in the shop since this person last looked.
             Before the language and theme switches, which are set once and
             forgotten, and after the help button — this is the one control in
             the row that changes by itself. --}}
        @include('partials.bell')

        {{-- Language and currency, in one menu — Soran, 2026-09-18:
             "move read in/type in currency to near languages… but have flag
             and select both languages and currency".

             ⚠️ The currency half used to be `<x-currency-lens>` on THIRTY-TWO
             screens, each in its own actions bar. One control that follows the
             reader everywhere is what he asked for and is less to look at.

             ⚠️ It is the READER'S lens and nothing else. The till is untouched:
             `money()` still converts only where a screen hands it a currency,
             and `CurrencyReachTest` still refuses it on sales/create. The sale
             screen's own currency combo is a different thing — see §2b. --}}
        @include('partials.preferences-menu')

        {{-- Section 8c: light / dark / auto, using Bootstrap 5.3's built-in
             dark mode. No custom dark stylesheet. --}}
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"
                    aria-label="{{ __('Change theme') }}">
                <i class="bi bi-{{ ['light' => 'sun', 'dark' => 'moon-stars', 'auto' => 'circle-half'][$currentTheme] ?? 'circle-half' }}"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                @foreach(['light' => __('Light'), 'dark' => __('Dark'), 'auto' => __('Auto')] as $value => $label)
                    <li>
                        <form action="{{ route('preferences.theme') }}" method="POST">
                            @csrf
                            <input type="hidden" name="theme" value="{{ $value }}">
                            <button type="submit" class="dropdown-item {{ $currentTheme === $value ? 'active' : '' }}">
                                {{ $label }}
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle"></i>
                <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header">{{ auth()->user()->email }}</h6></li>
                <li>
                    <a class="dropdown-item" href="{{ route('profile.edit') }}">
                        <i class="bi bi-sliders me-2"></i>{{ __('My preferences') }}
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-box-arrow-right me-2"></i>{{ __('Log out') }}
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
