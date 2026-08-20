{{-- Section 9b: a slim topbar holding global search, language switch, theme
     toggle and the user menu. --}}
<header class="app-topbar bg-body border-bottom px-3 py-2 d-flex align-items-center gap-3 no-print">
    @can('products.view')
        <form action="{{ route('products.index') }}" method="GET" class="flex-grow-1" style="max-width: 26rem">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-body-tertiary border-end-0">
                    <i class="bi bi-search"></i>
                </span>
                <input type="search" name="search" class="form-control border-start-0"
                       placeholder="{{ __('Search products by name, SKU or barcode') }}"
                       value="{{ request('search') }}" aria-label="{{ __('Search') }}">
            </div>
        </form>
    @endcan

    <div class="ms-auto d-flex align-items-center gap-2">
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
