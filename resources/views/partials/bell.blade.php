{{--
    The bell.

    Asked for by Soran: *"notification system to user get last changes or live
    changes"*, and then *"add section after alerts, news, history onl, add
    islamic Remembrance"* — so it holds two tabs, and only the first of them
    ever carries a count.

    ⚠️ Rendered on the server for the first paint and rebuilt in the browser
    from JSON afterwards. Both, on purpose: the server pass means the bell works
    for somebody with JavaScript off and shows a real count the moment the page
    arrives, and the browser pass is what makes it live.
--}}
@php($bell = app(App\Services\NotificationFeed::class))
@php($bellUnread = $bell->unreadCount(auth()->user()))
@php($bellItems = $bell->panel(auth()->user()))
@php($bellDhikr = ! (bool) (auth()->user()->getAttributes()['adhkar_off'] ?? false))
@php($bellEvery = (int) (auth()->user()->getAttributes()['adhkar_every'] ?? 5))

<div class="dropdown app-bell"
     data-feed="{{ route('notifications.feed') }}"
     data-seen="{{ route('notifications.seen') }}"
     data-empty="{{ __('Nothing new.') }}">
    <button class="btn btn-sm btn-outline-secondary position-relative" type="button"
            data-bs-toggle="dropdown" data-bs-auto-close="outside"
            aria-label="{{ __('Notifications') }}" title="{{ __('Notifications') }}">
        <i class="bi bi-bell" aria-hidden="true"></i>

        {{-- Hidden rather than absent when there is nothing, so the browser has
             something to write into without building markup by hand.

             ⚠️ The number lives in its OWN span, beside the word a screen
             reader needs. The poll writes into that span alone — writing the
             count over the whole badge would silently drop "unread", and the
             badge would then read as a bare "3" to anybody not looking at it. --}}
        <span class="app-bell-count badge rounded-pill text-bg-danger {{ $bellUnread === 0 ? 'd-none' : '' }}">
            <span class="app-bell-number">{{ $bellUnread > 99 ? '99+' : $bellUnread }}</span>
            <span class="visually-hidden">{{ __('unread') }}</span>
        </span>
    </button>

    <div class="dropdown-menu dropdown-menu-end app-bell-menu p-0">
        @if($bellDhikr)
            {{-- ⚠️ Notifications is always the tab that opens, whatever hour it
                 is. A panel that decided for itself which half you wanted would
                 hide the deleted invoice behind a tab on the morning somebody
                 deleted it. --}}
            <ul class="nav nav-tabs nav-fill" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" role="tab"
                            data-bs-target="#bell-pane-news" aria-controls="bell-pane-news"
                            aria-selected="true" type="button">
                        {{ __('Notifications') }}
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" role="tab"
                            data-bs-target="#bell-pane-dhikr" aria-controls="bell-pane-dhikr"
                            aria-selected="false" type="button">
                        {{ __('Remembrance') }}
                    </button>
                </li>
            </ul>
        @else
            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                <span class="fw-semibold">{{ __('Notifications') }}</span>
                <a href="{{ route('notifications.index') }}" class="small">{{ __('See all') }}</a>
            </div>
        @endif

        <div class="tab-content">
            <div class="tab-pane fade show active" id="bell-pane-news" role="tabpanel">
                @if($bellDhikr)
                    <div class="d-flex justify-content-end px-3 py-1 border-bottom">
                        <a href="{{ route('notifications.index') }}" class="small">{{ __('See all') }}</a>
                    </div>
                @endif

                <div class="app-bell-list overflow-auto">
                    @forelse($bellItems as $item)
                        @include('partials.bell-row', ['item' => $item])
                    @empty
                        <div class="px-3 py-4 text-center text-secondary small app-bell-empty">
                            {{ __('Nothing new.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            @if($bellDhikr)
                <div class="tab-pane fade" id="bell-pane-dhikr" role="tabpanel">
                    <div class="d-flex justify-content-end px-3 py-1 border-bottom">
                        <a href="{{ route('remembrance.index') }}" class="small">{{ __('See all') }}</a>
                    </div>

                    @include('partials.dhikr-tab')
                </div>
            @endif
        </div>
    </div>
</div>
