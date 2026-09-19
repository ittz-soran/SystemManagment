{{--
    The remembrance tab inside the bell.

    **Soran, 2026-09-15:** *"add section after alerts, news, history onl, add
    islamic Remembrance"*, and it gets *"own tab with no red badge"*.

    ⚠️ **No badge, and it never opens itself.** The bell's count is notifications
    only. Nothing here is unread, nothing here is owed, and a shop that is told
    it has "3 remembrances waiting" has been handed a chore. It is here when
    somebody reaches for it and silent when they do not — which is also why
    nothing about this ever appears over the till.
--}}
@php($shown = App\Support\Adhkar::forNow())

<div class="app-bell-list overflow-auto p-2">
    @if($shown['texts'] === [])
        <div class="px-2 py-4 text-center text-secondary small">
            {{ __('No remembrances are set up yet.') }}
        </div>
    @else
        @if($shown['window'] !== App\Support\Adhkar::ANY)
            <div class="text-secondary small px-1 pb-2">
                {{ $shown['window'] === App\Support\Adhkar::MORNING ? __('Morning') : __('Evening') }}
            </div>
        @endif

        @include('partials.dhikr-list', [
            'texts' => $shown['texts'],
            // ⚠️ Only this copy of the list drives the ones that show
            // themselves. The full page renders the same partial three times
            // over, and three timers would mean three at once.
            'every' => $bellEvery,
        ])
    @endif
</div>
