@extends('layouts.app')

@section('title', __('Expenses'))

@section('actions')
    @can('expenses.create')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expense-modal">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New expense') }}
        </button>
    @endcan
    @can('expense_categories.manage')
        <a href="{{ route('expense-categories.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-tags me-1"></i>{{ __('Categories') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-archived-notice :count="$archivedCount" />

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <span class="text-secondary">{{ __('Total for this selection') }}</span>
            <span class="fs-4 fw-semibold money">{{ money($total) }}</span>
        </div>
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small">{{ __('Search') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm" placeholder="{{ __('Title or document') }}">
            </div>
            <div class="col-md-3">
                <label for="category" class="form-label small">{{ __('Category') }}</label>
                <select id="category" name="category" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach($allCategories as $category)
                        <option value="{{ $category->id }}" @selected(request('category') == $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="from" class="form-label small">{{ __('From') }}</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label for="to" class="form-label small">{{ __('To') }}</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
                <a href="{{ route('expenses.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($expenses->isEmpty())
        <div class="card">
            <x-empty-state icon="cash-stack" :message="__('No expenses recorded yet.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Document') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Title') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th>{{ __('By') }}</th>
                        <th class="money">{{ __('Amount') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($expenses as $expense)
                        <tr>
                            <td class="fw-medium">
                                <x-document-link :document="$expense" :kind="false" />
                            </td>
                            <td dir="ltr">{{ $expense->expense_date->format(setting('date_format', 'Y-m-d')) }}</td>
                            <td>
                                {{ $expense->title }}
                                @if($expense->notes)
                                    <div class="small text-secondary">{{ $expense->notes }}</div>
                                @endif
                            </td>
                            <td>{{ $expense->category->name }}</td>
                            <td class="small text-secondary">{{ $expense->user->name }}</td>
                            <td class="money">{{ money($expense->amount, false) }}</td>
                            <td class="text-end">
                                <x-row-actions
                                    :view="route('expenses.show', $expense)"
                                    :edit-modal="Gate::allows('expenses.edit') ? '#expense-edit' : null"
                                    :edit-data="[
                                        'action' => route('expenses.update', $expense),
                                        'title' => $expense->title,
                                        'category' => $expense->expense_category_id,
                                        'amount' => $expense->amount,
                                        'date' => $expense->expense_date->toDateString(),
                                        'notes' => $expense->notes,
                                    ]"
                                    :delete="Gate::allows('expenses.delete') ? route('expenses.destroy', $expense) : null"
                                    :delete-label="__('Delete :document?', ['document' => $expense->document_no])" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $expenses->links() }}</div>
    @endif

    @can('expenses.edit')
        @include('expenses._edit-modal')
    @endcan

    @can('expenses.create')
        <div class="modal fade" id="expense-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" action="{{ route('expenses.store') }}" method="POST" data-guard-submit>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('New expense') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="expense-title" class="form-label">{{ __('Title') }}</label>
                            <input id="expense-title" name="title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="expense-category" class="form-label">{{ __('Category') }}</label>
                            <select id="expense-category" name="expense_category_id" class="form-select" required>
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="expense-amount" class="form-label">{{ __('Amount') }}</label>
                            <div class="input-group">
                                <input id="expense-amount" type="number" step="1" min="1" name="amount"
                                       class="form-control text-end" dir="ltr" required>
                                <span class="input-group-text">{{ __('IQD') }}</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="expense-date" class="form-label">{{ __('Date') }}</label>
                            <input id="expense-date" type="date" name="expense_date" class="form-control"
                                   value="{{ today()->toDateString() }}" required>
                        </div>
                        <div>
                            <label for="expense-notes" class="form-label">{{ __('Notes') }}</label>
                            <input id="expense-notes" name="notes" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save expense') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
