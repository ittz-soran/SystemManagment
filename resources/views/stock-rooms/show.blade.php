@extends('layouts.app')

@section('title', $room->name)

@section('content')
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <i class="bi bi-building fs-3 text-secondary" aria-hidden="true"></i>

            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold">
                    {{ $room->name }}
                    @if($room->is_main)
                        <span class="badge text-bg-primary ms-1">{{ __('Sells from here') }}</span>
                    @endif
                </div>
                <div class="text-secondary small">
                    {{ $room->is_main
                        ? __('Every sale draws from this room, and every purchase lands in it.')
                        : __('Overflow. Nothing is sold from here — move it to the main room first.') }}
                </div>
            </div>

            @can('stock_rooms.transfer')
                <a href="{{ route('stock-transfers.create', ['from' => $room->id]) }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-box-arrow-right me-1"></i>{{ __('Move stock out') }}
                </a>
            @endcan
        </div>
    </div>

    @if($lines->isEmpty())
        <div class="card">
            <x-empty-state icon="building" :message="__('This room is empty.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-cards align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th class="text-end">{{ __('Held here') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($lines as $line)
                        <tr>
                            <td data-label="{{ __('Product') }}">
                                <a href="{{ route('products.show', $line->id) }}" class="text-decoration-none">{{ $line->name }}</a>
                                <div class="small text-secondary app-code">{{ $line->sku }}</div>
                            </td>
                            <td class="text-end" data-label="{{ __('Held here') }}">{{ qty($line->units, $line->unit) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $lines->links() }}</div>
    @endif
@endsection
