@extends('layouts.app')

@section('title', __('Swaps'))

@section('actions')
    @can('swaps.create')
        <a href="{{ route('goods-back.index') }}" class="btn btn-primary">
            <i class="bi bi-arrow-left-right me-1"></i>{{ __('Swap a faulty item') }}
        </a>
    @endcan
@endsection

{{--
    ⚠️ **The same skeleton as the two return lists, in the same order** — Soran,
    2026-09-25: *"make all three purchase-returns, sale-returns, swaps have same
    designs or same like one"*. The lens note, the archived notice, four
    figures, the filter row, the table, pagination. This list had none of the
    middle three, which nobody had noticed for two days because there was
    nothing on the screen it was visibly different from.
--}}
@section('content')
    <x-lens-note :lens="$lens" />

    <x-archived-notice :count="$archivedCount" />

    <x-doc-stats :tiles="$stats" :filtered="$isFiltered" />

    <x-doc-filter :action="route('swaps.index')" prefix="SWP-" />

    @if($swaps->isEmpty())
        <div class="card">
            <x-empty-state icon="arrow-left-right"
                           :message="$isFiltered
                               ? __('No swaps match that. Clear the filter to see them all.')
                               : __('No swaps yet. When a faulty item comes back and you hand over the same thing again, it is recorded here.')"
                           :action="auth()->user()->hasPermission('swaps.create') ? route('goods-back.index') : null"
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
                        {{-- The swap's equivalent of the returns' Total column:
                             what the shop is out of pocket, which is the only
                             money figure a swap has. --}}
                        <th class="money">{{ __('Cost') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
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
                            <td class="money" data-label="{{ __('Cost') }}">{{ money($swap->cost(), in: $lens) }}</td>
                            <td class="list-card-actions text-end">
                                <x-row-actions :print="route('swaps.print', $swap)" />
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
