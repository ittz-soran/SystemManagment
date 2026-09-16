{{--
    A list of remembrances, each one tappable.

    Shared by the bell's tab and the full page, so the two cannot drift apart.

    ⚠️ `dir="rtl"` and `lang="ar"` on the text itself, not inherited from the
    page. The interface runs in four languages and the shop may well be reading
    it in English — but these words are Arabic whatever the surrounding page is,
    and a browser told otherwise will shape and break the line wrongly.
--}}
@php($today = App\Support\Adhkar::shopTime()->format('Y-m-d'))

<div class="app-dhikr-list" data-day="{{ $today }}" @isset($every) data-every="{{ $every }}" @endisset>
    @foreach($texts as $text)
        {{-- A button, not a div: this is tapped, so it must be reachable by a
             keyboard and announced as something that can be pressed. --}}
        <button type="button" class="app-dhikr w-100 text-start"
                data-dhikr="{{ App\Support\Adhkar::key($text) }}">
            <span class="app-dhikr-text" dir="rtl" lang="ar">{{ $text }}</span>
            <span class="app-dhikr-count badge rounded-pill text-bg-secondary">0</span>
        </button>
    @endforeach
</div>
