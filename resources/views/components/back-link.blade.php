{{--
    The way out of a page.

    It is the browser's own Back with a name on it: it goes to whatever page the
    reader came from, named after that page, and the arrow follows the reading
    direction. app.js does the naming, from the tab's own history.

    Given `to`, the link also has a destination the server can name — the list a
    detail page belongs to — which is what it falls back to when there is nothing
    behind this page: a bookmark, a typed URL, a link from outside. That href is
    a real route, so the button works with this script disabled and can be opened
    in a new tab. `remember` carries the first path segment of that list, so the
    filters and page number the reader had open survive the round trip.

    Given no `to`, there is no such fallback — a page reached from the sidebar is
    not "inside" anything — so the link starts hidden and appears only if the
    reader has somewhere to go back to. Every page gets one of these, which is
    why it has to be silent when it has nothing to offer.
--}}
@props(['to' => null, 'label' => null, 'remember' => null, 'permission' => null])

@php
    // A named destination is only offered to someone the destination would let
    // in. Where the reader came from needs no such check: they were just there.
    $named = filled($to) && ($permission === null || auth()->user()?->hasPermission($permission));

    // The shared $isRtl comes from the request's middleware. A Livewire screen
    // re-renders this outside that, so the direction is worked out from the
    // locale when nobody has shared it.
    $rtl = $isRtl ?? in_array(app()->getLocale(), \App\Http\Middleware\SetUserPreferences::RTL_LANGUAGES, true);
@endphp

<a href="{{ $named ? $to : '' }}"
   class="back-link d-inline-flex align-items-center gap-1 small text-decoration-none mb-2 {{ $named ? '' : 'd-none' }}"
   data-back-generic="{{ __('Back') }}"
   @unless($named) data-back-auto @endunless
   @if($remember) data-back-to="{{ $remember }}" @endif>
    <i class="bi bi-arrow-{{ $rtl ? 'right' : 'left' }}"></i>
    <span>{{ $named ? $label : __('Back') }}</span>
</a>
