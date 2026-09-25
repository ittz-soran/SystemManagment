@php
    $unit = $product->unit;
    $canGoBack = $state['canGoBack'];
@endphp

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="fs-5 fw-semibold">{{ $product->name }}</div>
                <div class="small text-secondary" dir="ltr">{{ $product->sku }}</div>
            </div>
            <a href="{{ route('goods-back.index', ['product' => $product->id]) }}"
               class="btn btn-sm btn-outline-secondary">{{ __('Choose another purchase') }}</a>
        </div>

        <hr>

        <div class="row g-3 small">
            <div class="col-6 col-md-3">
                <div class="text-secondary">{{ __('Bought on') }}</div>
                <div><x-document-link :document="$bought->purchase" :kind="false" /></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-secondary">{{ __('Supplier') }}</div>
                <div>{{ $bought->purchase->supplier->name }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-secondary">{{ __('You paid') }}</div>
                <div class="money">{{ money($bought->unit_price, false, $lens) }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-secondary">{{ __('Still in that batch') }}</div>
                <div>{{ number_format($state['inBatch']) }} {{ $unit }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        {{ __('Send it back to the supplier') }}
        <span class="badge text-bg-light app-code ms-1">PRT</span>
    </div>
    <div class="card-body">
        <ul class="small text-secondary">
            <li>{{ __('The units leave that purchase’s own batch — that one, never the oldest.') }}</li>
            <li>{{ __(':supplier credits what they were paid.', ['supplier' => $bought->purchase->supplier->name]) }}</li>
            <li>
                @if($state['owedOnThePurchase'] > 0)
                    {{ __('You still owe :amount on that purchase, so the credit comes off it first.', ['amount' => money($state['owedOnThePurchase'], false, $lens)]) }}
                @else
                    {{ __('That purchase is paid in full, so the money comes back.') }}
                @endif
            </li>
            <li>{{ __('No customer is involved and no invoice changes.') }}</li>
        </ul>

        @if($canGoBack < 1)
            <div class="d-flex align-items-start gap-2 text-secondary">
                <i class="bi bi-x-circle mt-1"></i>
                <div>
                    @if($state['inBatch'] < 1)
                        {{ __('That batch is empty — every one of them has been sold, so there is nothing left to send back.') }}
                    @else
                        {{ __('Every one of these has already been sent back.') }}
                    @endif
                </div>
            </div>
        @else
            <form action="{{ route('goods-back.send-back') }}" method="POST" data-guard-submit>
                @csrf
                <input type="hidden" name="purchase_item_id" value="{{ $bought->id }}">

                <div class="row g-3 align-items-end">
                    <div class="col-6 col-md-3">
                        <label for="send-qty" class="form-label">{{ __('How many') }}</label>
                        <div class="input-group">
                            <input id="send-qty" type="number" name="quantity" dir="ltr"
                                   class="form-control text-end" min="1" max="{{ $canGoBack }}" step="1"
                                   value="{{ old('quantity', 1) }}" required
                                   data-role="send-qty" data-price="{{ $bought->unit_price }}">
                            @if($unit !== '')<span class="input-group-text">{{ $unit }}</span>@endif
                        </div>
                        <div class="form-text">
                            @if($state['inBatch'] < $state['stillOnTheLine'])
                                {{ __('At most :count — that is what is left in the batch.', ['count' => number_format($canGoBack)]) }}
                            @else
                                {{ __('At most :count — that is what is left on the line.', ['count' => number_format($canGoBack)]) }}
                            @endif
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="send-method" class="form-label">{{ __('Credit by') }}</label>
                        <select id="send-method" name="payment_method" class="form-select">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="bank">{{ __('Bank') }}</option>
                            <option value="transfer">{{ __('Transfer') }}</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="send-reason" class="form-label">{{ __('Reason') }}</label>
                        <input id="send-reason" name="reason" class="form-control" maxlength="500"
                               value="{{ old('reason') }}"
                               placeholder="{{ __('Dead on arrival, wrong model, over-delivered…') }}">
                    </div>
                </div>

                <div class="alert alert-secondary py-2 small mt-3 mb-0">
                    {{ __('Credit') }}
                    <span class="money fw-semibold" data-role="send-total">{{ money($bought->unit_price, false, $lens) }}</span>
                </div>

                <div class="d-grid mt-3">
                    <button type="submit" class="btn btn-primary btn-lg" data-submitting-text="{{ __('Saving…') }}">
                        {{ __('Send it back') }}
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
