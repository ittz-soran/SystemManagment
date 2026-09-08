{{-- Section 9b: a slim topbar holding global search, language switch, theme
     toggle and the user menu. --}}
<header class="app-topbar bg-body border-bottom px-3 py-2 d-flex align-items-center gap-3 no-print">
    {{-- One box for the whole shop: a product, a person, a document number off a
         printed invoice, or the name of a screen. What it finds is decided by
         the server, which shows a reader only what they may open. --}}
    <div class="app-search flex-grow-1 position-relative" style="max-width: 30rem">
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
        </div>

        <div id="app-search-results" class="app-search-results dropdown-menu w-100 p-0 overflow-auto"
             role="listbox" aria-label="{{ __('Search') }}"
             data-url="{{ route('search') }}"
             data-empty="{{ __('Nothing found.') }}"></div>
    </div>

    <div class="ms-auto d-flex align-items-center gap-2">
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
             چوارشەممە ٩ی سەرماوەز rather than Wed, 09 Sep. The names travel as
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

        {{-- Language switch. Section 2: text and direction change together. --}}
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                    aria-label="{{ __('Change language') }}">
                <i class="bi bi-translate"></i>
                <span class="d-none d-md-inline">
                    {{ \App\Http\Middleware\SetUserPreferences::LANGUAGES[$currentLanguage] ?? $currentLanguage }}
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                @foreach(\App\Http\Middleware\SetUserPreferences::LANGUAGES as $code => $name)
                    <li>
                        <form action="{{ route('preferences.language') }}" method="POST">
                            @csrf
                            <input type="hidden" name="language" value="{{ $code }}">
                            <button type="submit" class="dropdown-item {{ $currentLanguage === $code ? 'active' : '' }}">
                                {{ $name }}
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>

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
