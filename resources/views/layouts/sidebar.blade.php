{{--
    Section 9b: "Only show nav items the user has permission for — never show a
    link that leads to 'access denied'." Every item below is wrapped in @can,
    which resolves through User::hasPermission() where admin short-circuits.
--}}
@php
    $nav = [
        null => [
            ['route' => 'dashboard', 'permission' => 'dashboard.view', 'icon' => 'speedometer2', 'label' => __('Dashboard')],
        ],
        __('Sell & buy') => [
            ['route' => 'sales.create', 'permission' => 'sales.create', 'icon' => 'cart-plus', 'label' => __('New sale')],
            ['route' => 'purchases.create', 'permission' => 'purchases.create', 'icon' => 'bag-plus', 'label' => __('New purchase')],
            ['route' => 'sales.index', 'permission' => 'sales.view', 'icon' => 'receipt', 'label' => __('Sales history')],
            ['route' => 'purchases.index', 'permission' => 'purchases.view', 'icon' => 'journal-text', 'label' => __('Purchase history')],
            ['route' => 'sale-returns.index', 'permission' => 'sale_returns.view', 'icon' => 'arrow-return-left', 'label' => __('Sale returns')],
            ['route' => 'purchase-returns.index', 'permission' => 'purchase_returns.view', 'icon' => 'arrow-return-right', 'label' => __('Purchase returns')],
        ],
        __('Catalogue') => [
            ['route' => 'products.index', 'permission' => 'products.view', 'icon' => 'box-seam', 'label' => __('Products')],
            ['route' => 'categories.index', 'permission' => 'categories.view', 'icon' => 'tags', 'label' => __('Categories')],
            ['route' => 'second-hand.index', 'permission' => 'products.view', 'icon' => 'arrow-repeat', 'label' => __('Second-hand')],
            ['route' => 'services.index', 'permission' => 'products.view', 'icon' => 'magic', 'label' => __('Services')],
            ['route' => 'stock-adjustments.index', 'permission' => 'stock_adjustments.view', 'icon' => 'sliders', 'label' => __('Stock adjustments')],
        ],
        __('People') => [
            ['route' => 'customers.index', 'permission' => 'customers.view', 'icon' => 'people', 'label' => __('Customers')],
            ['route' => 'suppliers.index', 'permission' => 'suppliers.view', 'icon' => 'truck', 'label' => __('Suppliers')],
            ['route' => 'users.index', 'permission' => 'users.view', 'icon' => 'person-badge', 'label' => __('Users')],
        ],
        __('Money') => [
            ['route' => 'payments.index', 'permission' => 'payments.view', 'icon' => 'cash-coin', 'label' => __('Payments')],
            ['route' => 'expenses.index', 'permission' => 'expenses.view', 'icon' => 'cash-stack', 'label' => __('Expenses')],
            ['route' => 'reports.index', 'permission' => 'reports.view', 'icon' => 'graph-up', 'label' => __('Reports')],
        ],
        __('System') => [
            ['route' => 'activity-logs.index', 'permission' => 'activity_logs.view', 'icon' => 'clock-history', 'label' => __('Activity log')],
            ['route' => 'data.index', 'permission' => 'products.view', 'icon' => 'arrow-down-up', 'label' => __('Import & export')],
            ['route' => 'settings.edit', 'permission' => 'settings.manage', 'icon' => 'gear', 'label' => __('Settings')],
        ],
    ];
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
                // Both conditions matter: the permission decides whether this user
                // may go there, and Route::has keeps a heading from appearing for
                // a module that has not been built yet.
                $visible = collect($items)->filter(
                    fn ($item) => Route::has($item['route']) && Gate::allows($item['permission'])
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
