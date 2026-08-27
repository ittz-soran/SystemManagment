{{--
    Section 9b: "Only show nav items the user has permission for — never show a
    link that leads to 'access denied'." Every item below is wrapped in @can,
    which resolves through User::hasPermission() where admin short-circuits.
--}}
@php
    // Shared with the search box, which lets a reader jump to a screen by name.
    $nav = App\Support\Navigation::groups();
@endphp

<aside class="app-sidebar d-flex flex-column p-2 no-print">
    <a href="{{ route('dashboard') }}" class="d-flex align-items-center gap-2 text-decoration-none text-body p-2 mb-2">
        @if(shop_logo())
            {{-- Section 9b: logos must NOT mirror in RTL. --}}
            <img src="{{ shop_logo() }}" alt="" height="28">
        @else
            <i class="bi bi-shop fs-4 text-primary"></i>
        @endif
        <span class="fw-semibold text-truncate app-sidebar-brand-text">
            {{ setting('shop_name', config('app.name')) }}
        </span>
    </a>

    <nav class="nav flex-column gap-1">
        @foreach($nav as $heading => $items)
            @php
                // Both conditions matter: Navigation::allows decides whether this
                // user may go there — permission, and role for the screens no
                // permission opens — and Route::has keeps a heading from
                // appearing for a module that has not been built yet.
                $visible = collect($items)->filter(
                    fn ($item) => Route::has($item['route'])
                        && App\Support\Navigation::allows(auth()->user(), $item)
                );
            @endphp

            @if($visible->isNotEmpty())
                @if($heading)
                    <div class="app-sidebar-heading px-2 pt-3 pb-1">{{ $heading }}</div>
                @endif

                @foreach($visible as $item)
                    @php
                        // An index item also owns its show/edit pages, but must not
                        // light up for the sibling "create" item, which has its own
                        // entry. Anything else matches only itself.
                        $module = Str::before($item['route'], '.');

                        $isActive = Str::endsWith($item['route'], '.index')
                            ? request()->routeIs($module.'.*') && ! request()->routeIs($module.'.create')
                            : request()->routeIs($item['route']);
                    @endphp

                        <a class="nav-link d-flex align-items-center gap-2 {{ $isActive ? 'active' : '' }}"
                           href="{{ route($item['route']) }}"
                           @if($isActive) aria-current="page" @endif>
                            <i class="bi bi-{{ $item['icon'] }}"></i>
                            <span class="text-truncate">{{ $item['label'] }}</span>
                        </a>
                @endforeach
            @endif
        @endforeach
    </nav>
</aside>
