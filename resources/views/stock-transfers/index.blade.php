@extends('layouts.app')

@section('title', __('Stock moves'))

@section('content')
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="room" class="form-label small">{{ __('Room') }}</label>
                <select id="room" name="room" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach($rooms as $room)
                        <option value="{{ $room->id }}" @selected(request('room') == $room->id)>{{ $room->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
                <a href="{{ route('stock-transfers.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
            <div class="col-md-4 text-md-end">
                @can('stock_rooms.transfer')
                    <a href="{{ route('stock-transfers.create') }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>{{ __('Move stock') }}
                    </a>
                @endcan
            </div>
        </div>
    </form>

    @if($transfers->isEmpty())
        <div class="card">
            <x-empty-state icon="box-arrow-right" :message="__('Nothing has been moved between rooms yet.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-cards align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Number') }}</th>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('From') }}</th>
                        <th>{{ __('To') }}</th>
                        <th class="text-end">{{ __('Units') }}</th>
                        <th>{{ __('User') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($transfers as $transfer)
                        <tr>
                            <td data-label="{{ __('Number') }}">
                                <a href="{{ route('stock-transfers.show', $transfer) }}" class="app-code text-decoration-none">
                                    {{ $transfer->document_no }}
                                </a>
                            </td>
                            <td class="small" data-label="{{ __('When') }}">
                                <span class="app-code">{{ $transfer->transferred_at->format('Y-m-d H:i') }}</span>
                            </td>
                            <td data-label="{{ __('From') }}">{{ $transfer->fromRoom->name }}</td>
                            <td data-label="{{ __('To') }}">{{ $transfer->toRoom->name }}</td>
                            <td class="text-end" data-label="{{ __('Units') }}">{{ number_format($transfer->unitsMoved()) }}</td>
                            <td class="small text-secondary" data-label="{{ __('User') }}">{{ $transfer->user?->name }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $transfers->links() }}</div>
    @endif
@endsection
