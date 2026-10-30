{{--
    Section 9b: "Only show nav items the user has permission for — never show a
    link that leads to 'access denied'." Every item below is wrapped in @can,
    which resolves through User::hasPermission() where admin short-circuits.
--}}
@php
    // Shared with the search box, which lets a reader jump to a screen by name.
    $nav = App\Support\Navigation::groups();
@endphp

{{--
    ⚠️ Three shapes, one markup — see the Shell section of app.scss.

      · phone  (< 768px)  a drawer that slides in over the page, with words
      · tablet (768-991)  the icon rail, unchanged
      · laptop (>= 992)   the full sidebar, unchanged

    `offcanvas-md` is Bootstrap's responsive offcanvas: below the breakpoint it
    behaves as a drawer, at and above it Bootstrap undoes all of that and the
    element is an ordinary block again. So the phone gets a real menu with
    labels instead of fourteen unlabelled icons eating an eighth of a 390px
    screen, and nothing changes on the iPad or the laptop, which Soran says are
    already right.
--}}
<aside id="app-nav" tabindex="-1"
       class="app-sidebar offcanvas-md offcanvas-start no-print"
       aria-label="{{ __('Menu') }}">
    <div class="offcanvas-body d-flex flex-column p-2">
    <div class="d-flex align-items-center gap-2 mb-2">
        <a href="{{ route('dashboard') }}" class="d-flex align-items-center gap-2 text-decoration-none text-body p-2 flex-grow-1 min-w-0">
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

        {{-- Only the drawer needs closing; the rail and the full sidebar are
             always there. --}}
        <button type="button" class="btn-close d-md-none me-1"
                data-bs-dismiss="offcanvas" data-bs-target="#app-nav"
                aria-label="{{ __('Close') }}"></button>
    </div>

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
    </div>
</aside>

{{--
    The menu keeps its place — Soran, 2026-10-30: "left slide after reload
    browser scroll position if forgetn not stay show scrolled before reload".

    Inline, straight after the menu, and not in app.js: app.js is a deferred
    module and runs after the first paint, so the menu would be drawn at the
    top and then jump. Here it is put back before anything is drawn. Kept for
    the tab, so a reload and a click on an item near the bottom both keep it.

    Both boxes, because which one scrolls depends on the screen: the menu
    itself beside the page, its body in the phone's drawer.
--}}
<script>
    (() => {
        const KEY = 'nav:sidebar-scroll';
        const menu = document.getElementById('app-nav');
        const boxes = [menu, menu.querySelector('.offcanvas-body')];

        try {
            const kept = JSON.parse(sessionStorage.getItem(KEY) || '[]');
            boxes.forEach((box, i) => { if (box && kept[i] > 0) box.scrollTop = kept[i]; });
        } catch { /* a private window: the menu starts at the top, as before */ }

        // Arriving by a link, this page's own item stays in sight — reached
        // from the search box, the kept place may be far from it. A reload, or
        // Back, puts the menu exactly where it was and nowhere else: that is
        // what was asked for, and the first version's nudge moved it.
        const how = performance.getEntriesByType?.('navigation')[0]?.type;
        const active = menu.querySelector('.nav-link.active');
        if (how === 'navigate' && active && menu.scrollHeight > menu.clientHeight) {
            const item = active.getBoundingClientRect();
            const box = menu.getBoundingClientRect();
            if (item.top < box.top) menu.scrollTop -= box.top - item.top + 8;
            else if (item.bottom > box.bottom) menu.scrollTop += item.bottom - box.bottom + 8;
        }

        addEventListener('pagehide', () => {
            try {
                sessionStorage.setItem(KEY, JSON.stringify(boxes.map((box) => box?.scrollTop ?? 0)));
            } catch { /* ignore */ }
        });
    })();
</script>
