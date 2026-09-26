@extends('layouts.app')

@section('title', __('Purchase returns'))

@section('actions')
    @can('purchase_returns.create')
        <a href="{{ route('goods-back.index') }}" class="btn btn-primary">
            <i class="bi bi-arrow-return-right me-1"></i>{{ __('Send an item back') }}
        </a>
    @endcan
@endsection

{{--
    ⚠️ **The same three parts the swaps list has** — Soran, 2026-09-26: *"have
    statics and have button like Swap faulty item in both"*. The figures and the
    filter arrived the day before; what was still missing was the way IN. This
    page had an empty `actions` section and an empty state that told the reader
    to "start one from a document" without saying which document or where.

    ⚠️ **It goes to the counter, not to a form of its own.** One screen asks the
    one question for all three documents — find the item, then it offers only
    what can really be done with it — so a second way in would be a second
    screen to teach and to keep true.
--}}

@section('content')
    <x-lens-note :lens="$lens" />

    <x-archived-notice :count="$archivedCount" />

    <x-doc-stats :tiles="$stats" :filtered="$isFiltered" />

    <x-doc-filter :action="route('purchase-returns.index')" prefix="PRT-" />

    @if($returns->isEmpty())
        <div class="card">
            <x-empty-state icon="arrow-return-right"
                           :message="$isFiltered
                               ? __('No returns match that. Clear the filter to see them all.')
                               : __('No returns yet. When something goes back to the supplier who sold it, it is recorded here.')"
                           :action="auth()->user()->hasPermission('purchase_returns.create') ? route('goods-back.index') : null"
                           :actionLabel="__('Send an item back')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-cards">
                    <thead>
                    <tr>
                        <th>{{ __('Document') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Against') }}</th>
                        <th>{{ __('Supplier') }}</th>
                        <th>{{ __('Reason') }}</th>
                        <th class="money">{{ __('Total') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($returns as $return)
                        <tr>
                            <td class="list-card-title"><x-document-link :document="$return" :kind="false" /></td>
                            <td data-label="{{ __('Date') }}"><span class="app-code">{{ $return->return_date->format(setting('date_format', 'Y-m-d')) }}</span></td>
                            <td data-label="{{ __('Against') }}">
                                <a href="{{ route('purchases.show', $return->purchase) }}" class="text-decoration-none">
                                    <x-document-link :document="$return->purchase" :kind="false" />
                                </a>
                            </td>
                            <td data-label="{{ __('Supplier') }}">{{ $return->supplier->name }}</td>
                            <td class="text-secondary small" data-label="{{ __('Reason') }}">{{ $return->reason ?: '—' }}</td>
                            <td class="money" data-label="{{ __('Total') }}">{{ money($return->total_amount, in: $lens) }}</td>
                            <td class="list-card-actions text-end">
                                <x-row-actions :print="route('purchase-returns.print', $return)" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $returns->links() }}</div>
    @endif
@endsection
