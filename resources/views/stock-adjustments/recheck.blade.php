@extends('layouts.app')

@section('title', __('Recheck stock'))
@section('subheading', __('Compares each cached quantity against its batches and movements'))

@section('back')
    <x-back-link :to="route('stock-adjustments.index')" :label="__('Stock adjustments')" remember="stock-adjustments" permission="stock_adjustments.view" />
@endsection

@section('content')
    {{-- Section 4: "Provide an admin Recheck stock action that compares every
         product's cached value against its batch sum and lists mismatches. If
         they ever differ, the batches win." --}}
    @if(empty($mismatches))
        <div class="card">
            <x-empty-state icon="check-circle"
                           :message="__('Every product matches its batches and its movements. The books are intact.')" />
        </div>
    @else
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            {{ trans_choice(
                '{1}:count product disagrees with its batches.|[2,*]:count products disagree with their batches.',
                count($mismatches),
                ['count' => count($mismatches)],
            ) }}
            {{ __('The batches are the truth — repairing rewrites the cached quantity from them.') }}
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th class="money">{{ __('Cached') }}</th>
                        <th class="money">{{ __('Batches') }}</th>
                        <th class="money">{{ __('Movements') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($mismatches as $row)
                        <tr>
                            <td>
                                <a href="{{ route('products.show', $row['product']) }}" class="text-decoration-none">
                                    {{ $row['product']->name }}
                                </a>
                                <div class="small text-secondary" dir="ltr">{{ $row['product']->sku }}</div>
                            </td>
                            <td class="money text-danger">{{ number_format($row['cached']) }}</td>
                            <td class="money fw-semibold">{{ number_format($row['batches']) }}</td>
                            <td class="money {{ $row['batches'] === $row['movements'] ? '' : 'text-danger' }}">
                                {{ number_format($row['movements']) }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                <form action="{{ route('stock.repair') }}" method="POST" data-guard-submit
                      onsubmit="return confirm(@js(__('Rewrite every cached quantity from its batches?')))">
                    @csrf
                    <button class="btn btn-primary">{{ __('Repair from batches') }}</button>
                </form>
            </div>
        </div>
    @endif

    <div class="card mt-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-semibold">{{ __('Customer and supplier balances') }}</div>
                <div class="small text-secondary">
                    {{ __('The ledger is the truth; the balance columns are caches of its latest balance.') }}
                </div>
            </div>
            <form action="{{ route('balances.recalculate') }}" method="POST" data-guard-submit>
                @csrf
                <button class="btn btn-outline-secondary">{{ __('Recalculate balances') }}</button>
            </form>
        </div>
    </div>
@endsection
