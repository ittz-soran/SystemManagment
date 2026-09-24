@extends('layouts.app')

@section('title', __('Swaps'))

@section('actions')
    @can('swaps.create')
        <a href="{{ route('swaps.create') }}" class="btn btn-primary">
            <i class="bi bi-arrow-left-right me-1"></i>{{ __('Swap a faulty item') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    @if($swaps->isEmpty())
        <div class="card">
            <x-empty-state icon="arrow-left-right"
                           :message="__('No swaps yet. When a faulty item comes back and you hand over the same thing again, it is recorded here.')"
                           :action="auth()->user()->hasPermission('swaps.create') ? route('swaps.create') : null"
                           :actionLabel="__('Swap a faulty item')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-cards">
                    <thead>
                    <tr>
                        <th>{{ __('Document') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Product') }}</th>
                        <th class="money">{{ __('Quantity') }}</th>
                        <th>{{ __('Against') }}</th>
                        <th>{{ __('Sent back') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($swaps as $swap)
                        <tr>
                            <td class="list-card-title">
                                <a href="{{ route('swaps.show', $swap) }}" class="text-decoration-none" dir="ltr">
                                    {{ $swap->document_no }}
                                </a>
                            </td>
                            <td data-label="{{ __('Date') }}">
                                <span class="app-code">{{ $swap->swapped_at->format(setting('date_format', 'Y-m-d')) }}</span>
                            </td>
                            <td data-label="{{ __('Product') }}">{{ $swap->product->name }}</td>
                            <td class="money" data-label="{{ __('Quantity') }}">{{ qty($swap->quantity, $swap->product->unit) }}</td>
                            <td data-label="{{ __('Against') }}">
                                <x-document-link :document="$swap->sale" :kind="false" />
                                @if($swap->sale?->customer)
                                    <div class="small text-secondary">{{ $swap->sale->customer->displayName() }}</div>
                                @endif
                            </td>
                            <td data-label="{{ __('Sent back') }}">
                                @if($swap->purchaseReturn)
                                    <x-document-link :document="$swap->purchaseReturn" :kind="false" />
                                @else
                                    {{-- No purchase behind the faulty unit, so nobody to bill. --}}
                                    <span class="text-secondary small">{{ __('The shop carried it') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $swaps->links() }}</div>
    @endif
@endsection
