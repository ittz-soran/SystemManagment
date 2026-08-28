@extends('layouts.app')

@section('title', $expense->document_no)
@section('subheading')
    {{ $expense->expense_date->format(setting('date_format', 'Y-m-d')) }}
    · {{ $expense->category->name }}
@endsection

@section('actions')
    @can('expenses.edit')
        <button type="button" class="btn btn-outline-secondary"
                data-bs-toggle="modal" data-bs-target="#expense-edit"
                data-action="{{ route('expenses.update', $expense) }}"
                data-title="{{ $expense->title }}"
                data-category="{{ $expense->expense_category_id }}"
                data-amount="{{ $expense->amount }}"
                data-date="{{ $expense->expense_date->toDateString() }}"
                data-notes="{{ $expense->notes }}">
            <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
        </button>
    @endcan

    @can('expenses.delete')
        <form action="{{ route('expenses.destroy', $expense) }}" method="POST"
              onsubmit="return confirm(@js(__('Delete :document?', ['document' => $expense->document_no])))">
            @csrf
            @method('DELETE')
            <x-return-to />
            <button class="btn btn-outline-danger">
                <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
            </button>
        </form>
    @endcan
@endsection

@section('back')
    <x-back-link :to="route('expenses.index')" :label="__('Expenses')" remember="expenses" permission="expenses.view" />
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">{{ __('Expense') }}</div>
                <div class="card-body">
                    <div class="d-flex align-items-baseline justify-content-between mb-3">
                        <span class="text-secondary">{{ $expense->title }}</span>
                        <span class="fs-3 fw-semibold money text-danger">−{{ money($expense->amount) }}</span>
                    </div>

                    <dl class="row mb-0 small">
                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Category') }}</dt>
                        <dd class="col-sm-8">
                            {{-- A category has no page of its own; the expense
                                 list filtered to it is what a reader wants. --}}
                            <a href="{{ route('expenses.index', ['category' => $expense->expense_category_id]) }}"
                               class="text-decoration-none">{{ $expense->category->name }}</a>
                        </dd>

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Date') }}</dt>
                        <dd class="col-sm-8" dir="ltr">
                            {{ $expense->expense_date->format(setting('date_format', 'Y-m-d')) }}
                        </dd>

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Recorded by') }}</dt>
                        <dd class="col-sm-8">{{ $expense->user->name }}</dd>

                        @if($expense->notes)
                            <dt class="col-sm-4 text-secondary fw-normal">{{ __('Notes') }}</dt>
                            <dd class="col-sm-8 mb-0">{{ $expense->notes }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            {{-- Section 4: an expense is money leaving the shop that no supplier
                 is owed for, so it touches no ledger and no stock. Saying so is
                 better than leaving the reader to wonder what is missing. --}}
            <div class="card">
                <div class="card-body text-secondary small mb-0">
                    {{ __('An expense is money out of the till on its own account. It changes no supplier balance and no stock.') }}
                </div>
            </div>
        </div>
    </div>
<x-record-history :for="$expense" />
@can('expenses.edit')
        @include('expenses._edit-modal')
    @endcan
@endsection
