@extends('layouts.app')

@section('title', $check->document_no)
@section('steps')<x-document-steps :document="$check" />@endsection
@section('subheading')
    {{ $check->scopeName() }} · {{ __('started :date by :name', ['date' => $check->started_at->format(setting('date_format', 'Y-m-d')), 'name' => $check->user->name]) }}
    @if($check->isFinished())
        · {{ __('finished :date', ['date' => $check->finished_at->format(setting('date_format', 'Y-m-d'))]) }}
    @endif
@endsection

@section('back')
    <x-back-link :to="route('stock-checks.index')" :label="__('Stock check')" permission="stock_checks.view" />
@endsection

{{--
    The round's page, which is also its report — PROJECT_DOC Section 9, Stock
    check: the counts, every wrong product with its difference and value, and
    how each was put right or that it is still to fix.
--}}
@section('actions')
    @if(! $check->isFinished())
        @can('stock_checks.count')
            <a href="{{ route('stock-checks.next', $check) }}" class="btn btn-primary">
                <i class="bi bi-clipboard-check me-1"></i>{{ __('Continue counting') }}
            </a>
            <form action="{{ route('stock-checks.finish', $check) }}" method="POST" data-guard-submit>
                @csrf
                <button type="submit" class="btn btn-outline-secondary">{{ __('Finish this round') }}</button>
            </form>
        @endcan
    @endif
    <a href="{{ route('stock-checks.print', $check) }}" target="_blank" class="btn btn-outline-secondary">
        <i class="bi bi-printer me-1"></i>{{ __('Print') }}
    </a>
@endsection

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            @include('stock-checks.partials.progress', ['progress' => $progress])
            @if($check->blind)
                <div class="small text-secondary mt-1">{{ __('Blind count') }}</div>
            @endif
        </div>
    </div>

    @include('stock-checks.partials.report')
@endsection
