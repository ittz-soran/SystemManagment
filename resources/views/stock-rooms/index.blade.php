@extends('layouts.app')

@section('title', __('Stock rooms'))

@section('content')
    {{--
        **Soran, 2026-09-15:** *"Store, one shop with several stoke-rooms or
        storage rooms"*.

        ⚠️ The main room is marked and cannot be closed, removed or demoted.
        Every sale in the shop draws from it, so a screen that let somebody take
        it away would be a screen that could stop the till.
    --}}
    <div class="alert alert-light border small d-flex gap-2 align-items-start">
        <i class="bi bi-info-circle mt-1 flex-shrink-0" aria-hidden="true"></i>
        <div>
            {{ __('Sales always draw from the main room. Other rooms hold overflow — buy into the main room, then move what will not fit.') }}
        </div>
    </div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-cards align-middle mb-0">
                <thead>
                <tr>
                    <th>{{ __('Room') }}</th>
                    <th class="text-end">{{ __('Units held') }}</th>
                    <th class="text-end">{{ __('Products') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($rooms as $room)
                    @php($stat = $held[$room->id] ?? null)
                    <tr>
                        <td data-label="{{ __('Room') }}">
                            <a href="{{ route('stock-rooms.show', $room) }}" class="fw-semibold text-decoration-none">
                                {{ $room->name }}
                            </a>
                            @if($room->is_main)
                                <span class="badge text-bg-primary ms-1">{{ __('Sells from here') }}</span>
                            @endif
                            @unless($room->is_active)
                                <span class="badge text-bg-secondary ms-1">{{ __('Closed') }}</span>
                            @endunless
                            @if($room->note)
                                <div class="small text-secondary">{{ $room->note }}</div>
                            @endif
                        </td>
                        <td class="text-end" data-label="{{ __('Units held') }}">
                            {{ number_format((int) ($stat->units ?? 0)) }}
                        </td>
                        <td class="text-end" data-label="{{ __('Products') }}">
                            {{ number_format((int) ($stat->lines ?? 0)) }}
                        </td>
                        {{-- `list-card-actions`: on a phone this row becomes a
                             card, and a bare button with no label would read as
                             a line of the card with nothing to say what it is.
                             ListCardTest knows this class and the two beside
                             it. --}}
                        <td class="text-end list-card-actions">
                            @if($canManage)
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#room-{{ $room->id }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                    <span class="visually-hidden">{{ __('Edit') }}</span>
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($canManage)
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#room-new">
            <i class="bi bi-plus-lg me-1"></i>{{ __('Add a room') }}
        </button>

        @include('stock-rooms._form', ['room' => null])

        @foreach($rooms as $room)
            @include('stock-rooms._form', ['room' => $room])
        @endforeach
    @endif
@endsection
