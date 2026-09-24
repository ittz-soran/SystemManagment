@extends('layouts.app')

@section('title', __('Take apart & build'))

@section('actions')
    @can('assemblies.create')
        <a href="{{ route('assemblies.create', ['direction' => 'apart']) }}" class="btn btn-primary">
            <i class="bi bi-box-arrow-down me-1"></i>{{ __('Take something apart') }}
        </a>
        <a href="{{ route('assemblies.create', ['direction' => 'together']) }}" class="btn btn-outline-primary">
            <i class="bi bi-boxes me-1"></i>{{ __('Build from parts') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    @if($assemblies->isEmpty())
        <div class="card">
            <x-empty-state icon="boxes"
                           :message="__('Nothing taken apart or built yet. Use this when you buy something whole and sell the pieces, or buy pieces and sell one thing.')"
                           :action="auth()->user()->hasPermission('assemblies.create') ? route('assemblies.create') : null"
                           :action-label="__('Take something apart')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-cards">
                    <thead>
                    <tr>
                        <th>{{ __('Document') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('What happened') }}</th>
                        <th class="money">{{ __('Value moved') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($assemblies as $document)
                        @php $whole = $document->whole(); @endphp
                        <tr>
                            <td class="list-card-title">
                                <a href="{{ route('assemblies.show', $document) }}" class="text-decoration-none app-code">
                                    {{ $document->document_no }}
                                </a>
                            </td>
                            <td data-label="{{ __('Date') }}">
                                <span class="app-code">{{ $document->assembled_at->format(setting('date_format', 'Y-m-d')) }}</span>
                            </td>
                            <td data-label="{{ __('What happened') }}">
                                @if($document->isApart())
                                    {{ __(':thing became :count pieces', [
                                        'thing' => $whole?->product?->name ?? '—',
                                        'count' => number_format($document->pieces()->count()),
                                    ]) }}
                                @else
                                    {{ __(':count parts became :thing', [
                                        'count' => number_format($document->pieces()->count()),
                                        'thing' => $whole?->product?->name ?? '—',
                                    ]) }}
                                @endif
                            </td>
                            <td class="money" data-label="{{ __('Value moved') }}">{{ money($document->total_cost, false, $lens) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $assemblies->links() }}</div>
    @endif
@endsection
