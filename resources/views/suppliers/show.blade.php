@extends('layouts.app')

@section('title', $supplier->name)
{{-- Guarded: @section with a NULL second argument is treated as the
     block form and opens an output buffer that never closes. --}}
@if($supplier->phone)
    @section('subheading', $supplier->phone)
@endif

@section('actions')
    @can('suppliers.edit')
        @if(! ($supplier->is_system ?? false))
            <button type="button" class="btn btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#supplier-edit"
                    data-action="{{ route('suppliers.update', $supplier) }}"
                    data-name="{{ $supplier->name }}"
                    data-phone="{{ $supplier->phone }}"
                    data-address="{{ $supplier->address }}">
                <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
            </button>
        @endif
    @endcan
@endsection

@section('back')
    <x-back-link :to="route('suppliers.index')" :label="__('Suppliers')" remember="suppliers" permission="suppliers.view" />
@endsection

@section('content')
    {{-- Section 9: a balance statement, read from the ledger. Section 4:
         account_transactions is the truth; the balance column is a cache. --}}
    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div class="text-secondary">{{ __('The shop owes') }}</div>
            <div class="fs-4 fw-semibold money">{{ money($supplier->balance) }}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">{{ __('Statement') }}</div>
        @if($transactions->isEmpty())
            <x-empty-state icon="journal" :message="__('No transactions yet.')" />
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('Type') }}</th>
                        <th>{{ __('Reference') }}</th>
                        <th class="money">{{ __('Change') }}</th>
                        <th class="money">{{ __('Balance') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($transactions as $transaction)
                        <tr>
                            <td class="small" dir="ltr">{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                            <td><span class="badge text-bg-light">{{ Str::headline($transaction->type) }}</span></td>
                            <td class="small">
                                <x-ledger-reference :transaction="$transaction" />
                            </td>
                            <td class="money {{ $transaction->amount > 0 ? 'text-danger' : 'text-success' }}">
                                {{ $transaction->amount > 0 ? '+' : '' }}{{ money($transaction->amount, false) }}
                            </td>
                            <td class="money fw-semibold">{{ money($transaction->balance_after, false) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-3">{{ $transactions->links() }}</div>

    @can('suppliers.edit')
        <x-person-edit-modal id="supplier-edit"
                             :title="__('Edit supplier')"
                             :save="__('Save supplier')" />
    @endcan
@endsection
