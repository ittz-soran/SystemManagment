@extends('layouts.app')

@section('title', $adjustment->document_no)
@section('subheading')
    {{ $adjustment->adjusted_at->format(setting('date_format', 'Y-m-d')) }}
    · <x-document-link :document="$adjustment->product" :kind="false" />
@endsection

@section('actions')
    @can('stock_adjustments.edit')
        <button type="button" class="btn btn-outline-secondary"
                data-bs-toggle="modal" data-bs-target="#adjustment-edit"
                data-action="{{ route('stock-adjustments.update', $adjustment) }}"
                data-product="{{ $adjustment->product?->name }}"
                data-direction="{{ $adjustment->direction }}"
                data-quantity="{{ $adjustment->quantity }}"
                data-cost="{{ $adjustment->unit_cost }}"
                data-reason="{{ $adjustment->reason }}"
                data-date="{{ $adjustment->adjusted_at->toDateString() }}"
                data-notes="{{ $adjustment->notes }}">
            <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
        </button>
    @endcan

    @can('stock_adjustments.delete')
        {{-- Section 8b: the units go back where they came from. The engine
             refuses if they have since been sold, so the button can be offered
             plainly and the refusal explains itself. --}}
        <form action="{{ route('stock-adjustments.destroy', $adjustment) }}" method="POST"
              onsubmit="return confirm(@js(__('Delete :document? :count units go back the way they came.', [
                  'document' => $adjustment->document_no,
                  'count' => $adjustment->quantity,
              ])))">
            @csrf
            @method('DELETE')
            <x-return-to />
            <button class="btn btn-outline-danger">
                <i class="bi bi-trash me-1"></i>{{ __('Delete adjustment') }}
            </button>
        </form>
    @endcan
@endsection

@section('back')
    <x-back-link :to="route('stock-adjustments.index')" :label="__('Stock adjustments')" remember="stock-adjustments" permission="stock_adjustments.view" />
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">{{ __('Adjustment') }}</div>
                <div class="card-body">
                    @php($in = $adjustment->direction === App\Models\StockAdjustment::DIRECTION_IN)

                    <div class="d-flex align-items-baseline justify-content-between mb-3">
                        <span class="text-secondary">
                            {{ $in ? __('Units into stock') : __('Units out of stock') }}
                        </span>
                        <span class="fs-3 fw-semibold money {{ $in ? 'text-success' : 'text-danger' }}">
                            {{ $in ? '+' : '−' }}{{ number_format($adjustment->quantity) }}
                        </span>
                    </div>

                    <dl class="row mb-0 small">
                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Product') }}</dt>
                        <dd class="col-sm-8">
                            <x-document-link :document="$adjustment->product" :kind="false" />
                        </dd>

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Reason') }}</dt>
                        <dd class="col-sm-8">
                            <span class="badge text-bg-light">{{ Str::headline($adjustment->reason) }}</span>
                        </dd>

                        {{-- Section 4: `out` has no typed cost — what it wrote off
                             is the true FIFO cost of the batches it consumed, which
                             is in the movements below rather than here. --}}
                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Unit cost') }}</dt>
                        <dd class="col-sm-8">
                            {{ $adjustment->unit_cost !== null
                                ? cost_money($adjustment->unit_cost, false)
                                : __('FIFO') }}
                        </dd>

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Date') }}</dt>
                        <dd class="col-sm-8" dir="ltr">
                            {{ $adjustment->adjusted_at->format(setting('date_format', 'Y-m-d')) }}
                        </dd>

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Recorded by') }}</dt>
                        <dd class="col-sm-8">{{ $adjustment->user->name }}</dd>

                        @if($adjustment->notes)
                            <dt class="col-sm-4 text-secondary fw-normal">{{ __('Notes') }}</dt>
                            <dd class="col-sm-8 mb-0">{{ $adjustment->notes }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            {{-- The point of reading an adjustment: what it did to the batches.
                 An incoming one opens a batch of its own; an outgoing one draws
                 down whichever batches FIFO reached, at their costs. --}}
            @if($batch)
                <div class="card mb-3">
                    <div class="card-header">{{ __('Batch it opened') }}</div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-secondary">{{ __('Cost each') }}</span>
                            <span class="money">{{ cost_money($batch->unit_cost, false) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-secondary">{{ __('In') }}</span>
                            <span class="money">{{ number_format($batch->quantity_in) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between fw-semibold">
                            <span>{{ __('Remaining') }}</span>
                            <span class="money">{{ number_format($batch->quantity_remaining) }}</span>
                        </li>
                    </ul>
                </div>
            @endif

            <div class="card">
                <div class="card-header">{{ __('Stock movements') }}</div>

                @if($movements->isEmpty())
                    <div class="card-body text-secondary small">{{ __('No movements recorded.') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>{{ __('When') }}</th>
                                <th>{{ __('Batch') }}</th>
                                <th class="money">{{ __('Quantity') }}</th>
                                <th class="money">{{ __('Cost each') }}</th>
                                <th class="money">{{ __('Value') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($movements as $movement)
                                <tr>
                                    <td class="small" dir="ltr">{{ $movement->occurred_at->format('Y-m-d H:i') }}</td>
                                    <td class="small text-secondary">#{{ $movement->stock_batch_id }}</td>
                                    <td class="money fw-semibold {{ $movement->quantity > 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $movement->quantity > 0 ? '+' : '' }}{{ number_format($movement->quantity) }}
                                    </td>
                                    <td class="money text-secondary">{{ cost_money($movement->unit_cost, false) }}</td>
                                    <td class="money">{{ cost_money(abs($movement->quantity) * $movement->unit_cost, false) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="4" class="text-end">{{ __('Value moved') }}</td>
                                <td class="money">
                                    {{ cost_money($movements->sum(fn ($m) => abs($m->quantity) * $m->unit_cost), false) }}
                                </td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
<x-record-history :for="$adjustment" />
@can('stock_adjustments.edit')
        @include('stock-adjustments._edit-modal')
    @endcan

    @endsection
