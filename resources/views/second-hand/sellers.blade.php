@extends('layouts.app')

@section('title', __('People you bought from'))
@section('subheading', __('Kept off the supplier list, counted the same way'))

@section('back')
    <x-back-link :to="route('second-hand.index')" :label="__('Second-hand')" remember="second-hand" permission="products.view" />
@endsection

@section('content')
    <div class="card card-body mb-3 d-flex flex-row justify-content-between align-items-center">
        <span class="text-secondary">{{ __('Still owed to them') }}</span>
        <span class="fs-4 fw-semibold money">{{ money($owed) }}</span>
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label small">{{ __('Name or phone') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-outline-secondary w-100">{{ __('Filter') }}</button>
            </div>
        </div>
    </form>

    @if($sellers->isEmpty())
        <x-empty-state icon="people" :message="__('Nobody yet. Anyone you buy a second-hand item from appears here.')" />
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th dir="ltr">{{ __('Phone') }}</th>
                        <th class="money">{{ __('Still owed') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($sellers as $seller)
                        <tr>
                            <td>
                                <a href="{{ route('suppliers.show', $seller) }}" class="text-decoration-none fw-medium">
                                    {{ $seller->name }}
                                </a>
                            </td>
                            <td dir="ltr" class="small text-secondary">{{ $seller->phone ?: '—' }}</td>
                            <td class="money fw-semibold {{ $seller->balance > 0 ? 'text-danger' : 'text-secondary' }}">
                                {{ money($seller->balance, false) }}
                            </td>
                            <td class="text-end">
                                <x-row-actions :view="route('suppliers.show', $seller)" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $sellers->links() }}</div>
    @endif
@endsection
