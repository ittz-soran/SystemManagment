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
    <meta name="theme-color" content="{{ App\Http\Controllers\InstallController::brandColour() }}">
    <link rel="apple-touch-icon" href="{{ route('install.icon', ['size' => 192, 'v' => app(App\Http\Controllers\InstallController::class)->iconVersion()]) }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ \Illuminate\Support\Str::limit(setting('shop_name', config('app.name')), 12, '') }}">

    {{-- Section 8c: shop info is used on printed invoices, the login page, and
         the browser title. --}}
    <title>{{ setting('shop_name', config('app.name')) }}</title>

    @include('partials.escape-html')

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])

    {{-- After the stylesheet, or Bootstrap's own :root wins on source order. --}}
    @include('partials.brand')

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
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="w-100" style="max-width: 26rem">
        <div class="text-center mb-4">
            @if(shop_logo())
                <img src="{{ shop_logo() }}" alt="" height="56" class="mb-2">
            @else
                <i class="bi bi-shop display-5 text-primary"></i>
            @endif
            <h1 class="h4 mt-2 mb-0">{{ setting('shop_name', config('app.name')) }}</h1>
            @if(setting('shop_address'))
                <div class="text-secondary small">{{ setting('shop_address') }}</div>
            @endif
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
</body>
</html>
