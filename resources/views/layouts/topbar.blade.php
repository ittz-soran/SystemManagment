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
        {{-- The wall clock. Twelve hours, because that is how the shop reads the
             time, and the machine's own so it agrees with the wall. --}}
        <div class="app-clock text-end d-none d-md-block lh-sm" dir="ltr">
            <div class="fw-semibold" id="app-clock-time">&nbsp;</div>
            <div class="text-secondary" id="app-clock-date">&nbsp;</div>
        </div>

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
