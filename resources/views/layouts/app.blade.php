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
<body class="bg-body-tertiary">
<div class="d-flex">
    @include('layouts.sidebar')

    <div class="flex-grow-1 min-vw-0 d-flex flex-column">
        @include('layouts.topbar')

        <main class="flex-grow-1 p-3 p-lg-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-0">@yield('heading', View::yieldContent('title'))</h1>
                    @hasSection('subheading')
                        <div class="text-secondary small">@yield('subheading')</div>
                    @endif
                </div>
                <div class="d-flex gap-2 no-print">@yield('actions')</div>
            </div>

            @include('partials.flash')

            @yield('content')
        </main>
    </div>
</div>

{{-- Section 9b: toasts sit top-right, and top-left in RTL. --}}
<x-number-pad />

<div class="toast-container position-fixed top-0 end-0 p-3 no-print" style="z-index: 1090">
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
