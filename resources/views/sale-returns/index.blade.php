@extends('layouts.app')

@section('title', __('Sale returns'))

@section('actions')
    @can('sale_returns.create')
        <a href="{{ route('goods-back.index') }}" class="btn btn-primary">
            <i class="bi bi-arrow-return-left me-1"></i>{{ __('Take an item back') }}
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

    <x-doc-filter :action="route('sale-returns.index')" prefix="SRT-" />

    @if($returns->isEmpty())
        <div class="card">
            <x-empty-state icon="arrow-return-left"
                           :message="$isFiltered
                               ? __('No returns match that. Clear the filter to see them all.')
                               : __('No returns yet. When a customer brings something back, it is recorded here.')"
                           :action="auth()->user()->hasPermission('sale_returns.create') ? route('goods-back.index') : null"
                           :actionLabel="__('Take an item back')" />
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
                        <th>{{ __('Customer') }}</th>
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
                                <a href="{{ route('sales.show', $return->sale) }}" class="text-decoration-none">
                                    <x-document-link :document="$return->sale" :kind="false" />
                                </a>
                            </td>
                            <td data-label="{{ __('Customer') }}">{{ $return->customer->displayName() }}</td>
                            <td class="text-secondary small" data-label="{{ __('Reason') }}">{{ $return->reason ?: '—' }}</td>
                            <td class="money" data-label="{{ __('Total') }}">{{ money($return->total_amount, in: $lens) }}</td>
                            <td class="list-card-actions text-end">
                                <x-row-actions :print="route('sale-returns.print', $return)" />
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
