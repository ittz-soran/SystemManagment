@extends('layouts.app')

@section('title', __('Purchase returns'))

@section('actions')
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    <x-archived-notice :count="$archivedCount" />

    <x-doc-stats :tiles="$stats" :filtered="$isFiltered" />

    <x-doc-filter :action="route('purchase-returns.index')" prefix="PRT-" />

    @if($returns->isEmpty())
        <div class="card">
            <x-empty-state icon="arrow-return-left" :message="__('No returns yet. Start one from a document.')" />
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
