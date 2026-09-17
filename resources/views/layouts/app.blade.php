{{--
    The shell (Section 9b): fixed left sidebar (right in RTL) with grouped
    navigation, plus a slim topbar holding global search, language switch, theme
    toggle and the user menu.
--}}
<!DOCTYPE html>
<html lang="{{ $currentLanguage }}"
      dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
      @if($currentTheme !== 'auto') data-bs-theme="{{ $currentTheme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Section 9b: what a phone needs to keep the shop on its home screen.

         The manifest is a route rather than a file because one codebase serves
         many shops and each has its own name, colour and logo — see
         InstallController.

         `theme-color` paints the phone's status bar, so an installed shop looks
         like one application rather than a page in a browser. iOS ignores the
         manifest's icons and reads `apple-touch-icon`, and ignores
         `display: standalone` unless told separately — hence the two
         apple-prefixed tags, which are old and still the only way. --}}
    <link rel="manifest" href="{{ route('install.manifest') }}">

    {{-- ⚠️ The worker's URL, because NOTHING WAS EVER REGISTERING IT.

         `sw.js` has been served since Add to Home Screen was built, and no page
         ever called `navigator.serviceWorker.register()`. A worker that is
         served and never registered does nothing at all — which is why Soran
         could add the shop to his Home Screen on 2026-09-17 and receive
         nothing: there was no worker to receive it. --}}
    <meta name="service-worker" content="{{ route('install.worker') }}">
    <meta name="theme-color" content="{{ App\Http\Controllers\InstallController::brandColour() }}">
    <link rel="apple-touch-icon" href="{{ route('install.icon', ['size' => 192, 'v' => app(App\Http\Controllers\InstallController::class)->iconVersion()]) }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ \Illuminate\Support\Str::limit(setting('shop_name', config('app.name')), 12, '') }}">

    {{--
        Writing an amount the way the server writes it.

        The cart screens cannot ask the server for a total that changes on every
        keystroke, so they format it themselves — and there must be exactly one
        answer to "how is money written", or the running total disagrees with
        the invoice that gets saved. This mirrors App\Support\Money::format,
        trailing-zero rule included: 250,000 reads 250 and 15,500 reads 15.5.

        ⚠️ Inline and in the head, not in app.js. `@vite` emits a deferred
        module, which runs after every inline script on the page — including the
        cart's, which draws its first total while parsing. A formatter defined
        in the bundle is undefined at the moment it is first needed.

        The divisor is written in rather than hard-coded, for the same reason it
        is a setting on the server: the day the dinar loses three zeros, nothing
        here should need editing. See App\Support\Money.
    --}}
    <script>
        window.appMoney = (function () {
            const per = {{ App\Support\Money::minorPerMajor() }};
            const places = Math.round(Math.log10(per));
            const group = new Intl.NumberFormat('en-US');

            return function (stored) {
                const value = Math.round(Number(stored) || 0);

                if (per === 1) {
                    return group.format(value);
                }

                // Split before dividing: the fractional part of 15500 / 1000 is
                // not exactly .5 in binary, and a running total is the wrong
                // place to discover that.
                const size = Math.abs(value);
                const major = group.format(Math.floor(size / per));
                const minor = String(size % per).padStart(places, '0').replace(/0+$/, '');

                return (value < 0 ? '-' : '') + major + (minor === '' ? '' : '.' + minor);
            };
        })();

        /*
         * The same figure, read through a lens — Section 2b.
         *
         * ⚠️ The lens is an ARGUMENT, never a global. Section 2b keeps the lens
         * off the till, and the way that is enforced is that nothing converts
         * unless a screen hands it a currency. A `window.appLens` that every
         * script could reach would put the lens back on the sale screen the
         * first time somebody reused a helper.
         *
         * `lens` is `{rate, decimals, mark}` — rate being base minor units per
         * one major unit of that currency, the shape `Money::RATE_SCALE`
         * already divides out on the server.
         */
        window.appMoneyIn = function (stored, lens) {
            if (! lens || ! lens.rate) {
                return window.appMoney(stored);
            }

            const places = Number(lens.decimals) || 0;

            return (Math.round(Number(stored) || 0) / lens.rate).toLocaleString('en-US', {
                minimumFractionDigits: places,
                maximumFractionDigits: places,
            });
        };
    </script>

    @include('partials.escape-html')

    <title>@yield('title', __('Dashboard')) · {{ setting('shop_name', config('app.name')) }}</title>

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])

    {{-- After the stylesheet, never before: Bootstrap declares the same
         variables in its own :root, so an earlier override loses to it. --}}
    @include('partials.brand')

    {{-- Section 8c: 'auto' follows the OS. Applied before first paint so the
         page never flashes the wrong theme. --}}
    @if($currentTheme === 'auto')
        <script>
            document.documentElement.setAttribute(
                'data-bs-theme',
                window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
            );
        </script>
    @endif
</head>
{{-- data-base: where the app starts. On shared hosting the shop is reached at
     /sys/public/, and app.js needs to know which part of the path is the
     address and which part is the screen. --}}
<body class="bg-body-tertiary"
      data-hold-hint="{{ __('Hold to save') }}"
      data-base="{{ rtrim(parse_url(url('/'), PHP_URL_PATH) ?: '', '/') }}">
<div class="d-flex">
    @include('layouts.sidebar')

    {{-- ⚠️ `min-w-0`, and it matters more than it looks.

         This column is a flex item, and a flex item's default `min-width: auto`
         refuses to shrink below its own content. A wide table therefore does not
         scroll inside its `.table-responsive` — it pushes this column, the
         topbar and the whole shell wider than the screen, and the reader drags
         the entire page sideways to reach the second half of a row.

         It carried `min-vw-0` for a year, which is not a Bootstrap class and is
         defined nowhere: the rule said nothing at all. On a 390px phone the
         products list measured 784px, sales 763, payments 738. See
         LayoutClassTest, which now fails on a class that resolves to nothing. --}}
    <div class="flex-grow-1 min-w-0 d-flex flex-column">
        @include('layouts.topbar')

        @include('partials.screen-help')

        <main class="flex-grow-1 p-3 p-lg-4">
            {{-- Storage running out is the one warning that has to arrive
                 before the thing it warns about, because the thing it warns
                 about is the shop being unable to record a sale. --}}
            {{-- Before the storage banner: a shop running code its database
                 has not caught up with is broken now, not soon. --}}
            <x-update-banner />
            <x-storage-banner />
            <x-licence-banner />

            {{-- Every page has a way back. A screen that belongs to a list
                 names it; anywhere else the link stays hidden until the reader
                 has somewhere to return to, which app.js decides from the tab's
                 own history. --}}
            <div class="no-print">
                @hasSection('back')
                    @yield('back')
                @else
                    <x-back-link />
                @endif
            </div>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div>
                    {{-- Printed raw on purpose, and only safe because of what
                         is on the other end: every page sets its title with
                         @section('title', $value), and Laravel escapes a section
                         given a value. Passing that already-escaped string as
                         @yield's default escaped it a second time, so a product
                         called "Tom & Jerry" was titled "Tom &amp;amp; Jerry" on
                         its own page. Nobody in this shop sells anything with an
                         ampersand in it yet — but Import & export is a page, and
                         it read wrong from the day it was written. --}}
                    <h1 class="h4 mb-0">
                        @hasSection('heading')
                            @yield('heading')
                        @else
                            {!! View::yieldContent('title') !!}
                        @endif
                    </h1>
                    @hasSection('subheading')
                        <div class="text-secondary small">@yield('subheading')</div>
                    @endif
                </div>
                {{-- ⚠️ `flex-wrap`, and a phone is why.

                     Most screens put three things in here — the currency lens,
                     one or two buttons, sometimes a Delete — and on a laptop
                     they sit in a row with room to spare. On a 390px phone that
                     row measured 546px on a product page and dragged the whole
                     screen sideways, because a `d-flex` with no wrap would
                     rather overflow than go to a second line. --}}
                <div class="d-flex flex-wrap gap-2 no-print">@yield('actions')</div>
            </div>

            @include('partials.flash')

            @yield('content')
        </main>
    </div>
</div>

{{-- Section 9b: toasts sit top-right, and top-left in RTL. --}}
<x-number-pad />

<div class="toast-container position-fixed top-0 end-0 p-3 no-print" style="z-index: 1090"
     data-close="{{ __('Close') }}">
    @foreach(['success' => 'success', 'error' => 'danger', 'warning' => 'warning'] as $key => $variant)
        @if(session($key))
            <div class="toast align-items-center text-bg-{{ $variant }} border-0" role="alert" aria-live="polite">
                <div class="d-flex">
                    <div class="toast-body">{{ session($key) }}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto"
                            data-bs-dismiss="toast" aria-label="{{ __('Close') }}"></button>
                </div>
            </div>
        @endif
    @endforeach
</div>

@stack('scripts')
</body>
</html>
