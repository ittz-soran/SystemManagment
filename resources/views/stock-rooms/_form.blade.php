{{-- One room's details, as a modal. Shared by Add and Edit so the two cannot
     drift into asking different questions. --}}
@php($id = $room?->id ? 'room-'.$room->id : 'room-new')

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST"
              action="{{ $room ? route('stock-rooms.update', $room) : route('stock-rooms.store') }}"
              data-guard-submit>
            @csrf
            @if($room) @method('PUT') @endif

            <div class="modal-header">
                <h5 class="modal-title">{{ $room ? __('Edit room') : __('Add a room') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label for="{{ $id }}-name" class="form-label">{{ __('Name') }}</label>
                    <input id="{{ $id }}-name" name="name" class="form-control" required maxlength="120"
                           value="{{ old('name', $room?->name) }}">
                </div>

                <div class="mb-3">
                    <label for="{{ $id }}-note" class="form-label">{{ __('Note') }}</label>
                    <input id="{{ $id }}-note" name="note" class="form-control" maxlength="255"
                           value="{{ old('note', $room?->note) }}">
                    <div class="form-text">{{ __('Where it is, or who has the key. Shown on this page only.') }}</div>
                </div>

                <div class="mb-3">
                    <label for="{{ $id }}-sort" class="form-label">{{ __('Order') }}</label>
                    <input id="{{ $id }}-sort" name="sort_order" type="number" min="0" max="999" dir="ltr"
                           class="form-control text-end" value="{{ old('sort_order', $room?->sort_order ?? 0) }}">
                </div>

                @if(! $room?->is_main)
                    <div class="form-check form-switch">
                        {{-- The unchecked box has to reach the server too. --}}
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="{{ $id }}-active" name="is_active" value="1"
                               @checked(old('is_active', $room?->is_active ?? true))>
                        <label class="form-check-label" for="{{ $id }}-active">{{ __('Open') }}</label>
                        <div class="form-text">
                            {{ __('A closed room keeps what it holds and still shows on reports, but nothing new can be moved into it.') }}
                        </div>
                    </div>
                @else
                    <div class="alert alert-light border small mb-0">
                        {{ __('This is the room the till sells from. It cannot be closed or removed.') }}
                    </div>
                @endif
            </div>

            <div class="modal-footer justify-content-between">
                @if($room && ! $room->is_main)
                    {{-- Its own form, because a form inside a form is not a
                         thing HTML has. Posted from the footer so Delete sits
                         where a reader expects it. --}}
                    <button type="submit" class="btn btn-outline-danger"
                            form="{{ $id }}-delete">{{ __('Remove') }}</button>
                @else
                    <span></span>
                @endif

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button class="btn btn-primary">{{ __('Save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

@if($room && ! $room->is_main)
    <form id="{{ $id }}-delete" method="POST" action="{{ route('stock-rooms.destroy', $room) }}" class="d-none">
        @csrf
        @method('DELETE')
    </form>
@endif
