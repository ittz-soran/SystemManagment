@extends('layouts.app')

@section('title', __('To fix'))
@section('subheading', __('Every wrong count not yet put right, from every round'))

@section('back')
    <x-back-link :to="route('stock-checks.index')" :label="__('Stock check')" permission="stock_checks.view" />
@endsection

{{--
    The To fix list — PROJECT_DOC Section 9, Stock check. Each can still have
    its ADJ made, or be marked fixed with a note when the fix was something
    else: a return, a sale entered late.
--}}
@section('content')
    @if($lines->isEmpty())
        <div class="card">
            <x-empty-state icon="check-circle" :message="__('Nothing to fix. Every wrong count has been put right.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-cards">
                    <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('Round') }}</th>
                        <th class="money">{{ __('Difference') }}</th>
                        <th>{{ __('Note') }}</th>
                        <th class="text-end">{{ __('Put right') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($lines as $line)
                        @php
                            $difference = $line->difference();
                            $roomCheck = $line->stockCheck->scope === App\Models\StockCheck::SCOPE_ROOM && $line->stockCheck->room_id !== $mainRoom;
                        @endphp
                        <tr>
                            <td class="list-card-title" dir="auto">
                                <a href="{{ route('products.show', $line->product) }}" class="text-decoration-none">{{ $line->product->name }}</a>
                                <div class="small text-secondary">
                                    {{ __('the system said :expected, the shelf had :counted', ['expected' => qty($line->expected, $line->product->unit), 'counted' => qty($line->counted, $line->product->unit)]) }}
                                </div>
                            </td>
                            <td class="small" data-label="{{ __('Round') }}">
                                <a href="{{ route('stock-checks.show', $line->stockCheck) }}" class="text-decoration-none">{{ $line->stockCheck->document_no }}</a>
                                <div class="text-secondary">{{ $line->checked_at?->format(setting('date_format', 'Y-m-d')) }} · {{ $line->checker?->name }}</div>
                            </td>
                            <td class="money fw-semibold {{ $difference > 0 ? 'text-success' : 'text-danger' }}" data-label="{{ __('Difference') }}">
                                {{ $difference > 0 ? '+' : '−' }}{{ number_format(abs($difference)) }}
                            </td>
                            <td class="small" data-label="{{ __('Note') }}" dir="auto">{{ $line->note }}</td>
                            <td class="list-card-actions text-end">
                                @can('stock_checks.count')
                                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                                        @if($canAdjust && ! $roomCheck)
                                            <form action="{{ route('stock-checks.adjust', $line) }}" method="POST" class="d-flex gap-2 align-items-center" data-guard-submit>
                                                @csrf
                                                @if($difference > 0)
                                                    <div style="max-width: 9rem">
                                                        <x-money-input name="unit_cost" :id="'unit_cost_'.$line->id"
                                                                       :value="app(App\Services\StockCheckService::class)->latestCost($line->product)" :lens="$lens" min="0" />
                                                    </div>
                                                @endif
                                                <button type="submit" class="btn btn-sm btn-primary">{{ __('Make the ADJ') }}</button>
                                            </form>
                                        @endif
                                        <form action="{{ route('stock-checks.fixed', $line) }}" method="POST" class="d-flex gap-2" data-guard-submit>
                                            @csrf
                                            <input type="text" name="note" maxlength="500" class="form-control form-control-sm" dir="auto"
                                                   placeholder="{{ __('How, e.g. SRT-00012') }}" aria-label="{{ __('How it was put right') }}" style="max-width: 12rem">
                                            <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Mark fixed') }}</button>
                                        </form>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $lines->links() }}</div>
    @endif
@endsection
