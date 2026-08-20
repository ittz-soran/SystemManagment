@extends('layouts.app')

@section('title', $customer->displayName())
{{-- Guarded: @section with a NULL second argument is treated as the
     block form and opens an output buffer that never closes. --}}
@if($customer->phone)
    @section('subheading', $customer->phone)
@endif

@section('content')
    {{-- Section 9: a balance statement, read from the ledger. Section 4:
         account_transactions is the truth; the balance column is a cache. --}}
    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div class="text-secondary">{{ __('Owes the shop') }}</div>
            <div class="fs-4 fw-semibold money">{{ money($customer->balance) }}</div>
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
                            <td class="small text-secondary" dir="ltr">{{ $transaction->notes ?: '—' }}</td>
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
@endsection
