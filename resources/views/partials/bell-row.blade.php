{{--
    One line of the bell.

    Its own file because the browser builds the same shape from the JSON feed,
    and the two have to stay the same shape — a row written in two places
    drifts, and the drift shows up as a panel that looks different thirty
    seconds after arrival than it did on arrival.

    Always an <a>, with the href left off when the entry leads nowhere: an
    anchor without one is valid and renders as plain text, which is a smaller
    thing to get right than two branches of near-identical markup.
--}}
@php($href = App\Support\Notifications::linkFor($item->module, $item->action, $item->record_id))

<a @if($href) href="{{ $href }}" @endif
   class="app-bell-row d-flex gap-2 px-3 py-2 text-decoration-none text-body border-bottom {{ $item->is_unread ? 'is-unread' : '' }}">
    <i class="bi {{ App\Support\Notifications::iconFor($item->action) }} mt-1 flex-shrink-0 {{ $item->tier === App\Support\Notifications::ALERT ? 'text-danger' : 'text-secondary' }}"
       aria-hidden="true"></i>

    <span class="min-w-0 flex-grow-1">
        <span class="app-bell-text d-block small">{{ $item->description }}</span>
        <span class="app-bell-meta d-block text-secondary">
            {{ $item->user?->name ?? __('Somebody') }} · {{ App\Support\Notifications::when($item->created_at) }}
        </span>
    </span>
</a>
