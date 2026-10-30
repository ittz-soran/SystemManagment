@extends('layouts.print')

@section('title', $check->document_no)
@section('doc-title', __('Stock check'))
@section('doc-number', $check->document_no)
@section('doc-date', $check->started_at->format(setting('date_format', 'Y-m-d')))

{{-- A round's report on paper — PROJECT_DOC Section 9, Stock check. --}}
@section('content')
    <div class="mb-3">
        <div class="fw-semibold">{{ $check->scopeName() }}</div>
        <div class="small">
            {{ __(':done of :total', ['done' => $progress['done'], 'total' => $progress['total']]) }}
            · {{ __('confirmed :n', ['n' => $progress['confirmed']]) }}
            · {{ __('wrong :n', ['n' => $progress['wrong']]) }}
            · {{ __('skipped :n', ['n' => $progress['skipped']]) }}
        </div>
        <div class="small">
            {{ __('started :date by :name', ['date' => $check->started_at->format(setting('date_format', 'Y-m-d')), 'name' => $check->user->name]) }}
            @if($check->isFinished())
                · {{ __('finished :date', ['date' => $check->finished_at->format(setting('date_format', 'Y-m-d'))]) }}
            @else
                · {{ __('still open') }}
            @endif
        </div>
    </div>

    @include('stock-checks.partials.report')
@endsection
