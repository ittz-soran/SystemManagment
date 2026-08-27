@extends('layouts.app')

@section('title', $adjustment->document_no)
@section('heading', __('Edit :document', ['document' => $adjustment->document_no]))
@section('subheading')
    <x-document-link :document="$adjustment->product" :kind="false" />
@endsection

@section('back')
    <x-back-link :to="route('stock-adjustments.show', $adjustment)"
                 :label="$adjustment->document_no"
                 permission="stock_adjustments.view" />
@endsection

@section('content')
    {{-- Section 8: an edit reverses the document and applies it again, so the
         batches end up as they would have if it had been right the first time.
         The product is not part of it — an adjustment is a note about one
         product's shelf, and pointing it at a different product is two
         documents rather than an edit. --}}
    <div class="row">
        <div class="col-lg-7">
            <form action="{{ route('stock-adjustments.update', $adjustment) }}" method="POST"
                  class="card" data-guard-submit>
                @csrf
                @method('PUT')

                <div class="card-header">{{ __('Adjustment') }}</div>

                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-label">{{ __('Product') }}</div>
                        <div class="form-control-plaintext">
                            {{ $adjustment->product->name }}
                            <span class="text-secondary small ms-2" dir="ltr">{{ $adjustment->product->sku }}</span>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label for="direction" class="form-label">{{ __('Direction') }}</label>
                            <select id="direction" name="direction" class="form-select">
                                <option value="out" @selected(old('direction', $adjustment->direction) === 'out')>
                                    {{ __('Out — remove stock') }}
                                </option>
                                <option value="in" @selected(old('direction', $adjustment->direction) === 'in')>
                                    {{ __('In — add stock') }}
                                </option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="quantity" class="form-label">{{ __('Quantity') }}</label>
                            <input id="quantity" type="number" step="1" min="1" name="quantity" required
                                   class="form-control text-end" dir="ltr"
                                   data-numpad="{{ __('Quantity') }}" data-numpad-min="1"
                                   value="{{ old('quantity', $adjustment->quantity) }}">
                        </div>
                    </div>

                    {{-- Section 4: FIFO needs a cost for every unit, so an
                         incoming adjustment cannot leave this blank. Going out,
                         the cost comes from the batches consumed. --}}
                    <div class="mb-3 {{ old('direction', $adjustment->direction) === 'in' ? '' : 'd-none' }}"
                         id="cost-wrap">
                        <label for="unit_cost" class="form-label">{{ __('Cost each') }}</label>
                        <div class="input-group">
                            <input id="unit_cost" type="number" step="1" min="0" name="unit_cost"
                                   class="form-control text-end" dir="ltr"
                                   data-numpad="{{ __('Cost each') }}"
                                   value="{{ old('unit_cost', $adjustment->unit_cost) }}">
                            <span class="input-group-text">{{ __('IQD') }}</span>
                        </div>
                        <div class="form-text">{{ __('Required when adding stock — FIFO needs a cost for every unit.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label for="reason" class="form-label">{{ __('Reason') }}</label>
                        <select id="reason" name="reason" class="form-select" required>
                            @foreach($reasons as $reason)
                                <option value="{{ $reason }}" @selected(old('reason', $adjustment->reason) === $reason)>
                                    {{ Str::headline($reason) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="adjusted_at" class="form-label">{{ __('Date') }}</label>
                        <input id="adjusted_at" type="date" name="adjusted_at" class="form-control" required
                               value="{{ old('adjusted_at', $adjustment->adjusted_at->toDateString()) }}">
                    </div>

                    <div>
                        <label for="notes" class="form-label">{{ __('Notes') }}</label>
                        <input id="notes" name="notes" class="form-control"
                               placeholder="{{ __('What happened?') }}"
                               value="{{ old('notes', $adjustment->notes) }}">
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end gap-2">
                    <a href="{{ route('stock-adjustments.show', $adjustment) }}"
                       class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('Save adjustment') }}</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Cost is asked for only when units are coming in.
        (() => {
            const direction = document.getElementById('direction');
            const wrap = document.getElementById('cost-wrap');

            direction.addEventListener('change', () => {
                wrap.classList.toggle('d-none', direction.value !== 'in');
            });
        })();
    </script>
@endsection
