<!DOCTYPE html>
<html lang="{{ $currentLanguage }}"
      dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
      @if($currentTheme !== 'auto') data-bs-theme="{{ $currentTheme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Section 8c: shop info is used on printed invoices, the login page, and
         the browser title. --}}
    <title>{{ setting('shop_name', config('app.name')) }}</title>

    <style>
        :root {
            --bs-primary: {{ setting('primary_color', '#0d6efd') }};
            --bs-body-font-family: {{ setting('font_family', 'system-ui, sans-serif') }};
        }
    </style>

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])

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
            @if(setting('shop_logo'))
                <img src="{{ asset(setting('shop_logo')) }}" alt="" height="56" class="mb-2">
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
