@extends('layouts.app')

@section('title', __('Buy a second-hand item'))
@section('subheading', __('The item and the purchase are recorded together'))

@section('back')
    <x-back-link :to="route('second-hand.index')" :label="__('Second-hand')" remember="second-hand" permission="products.view" />
@endsection

@section('content')
    <form action="{{ route('second-hand.store') }}" method="POST" data-guard-submit>
        @csrf

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card mb-3">
                    <div class="card-header">{{ __('The item') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">{{ __('What is it') }}</label>
                            <input id="name" name="name" value="{{ old('name') }}" required autofocus
                                   class="form-control @error('name') is-invalid @enderror"
                                   placeholder="{{ __('Xbox Series S 512GB') }}">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="condition_note" class="form-label">{{ __('Condition') }}</label>
                            <input id="condition_note" name="condition_note" value="{{ old('condition_note') }}"
                                   class="form-control @error('condition_note') is-invalid @enderror"
                                   placeholder="{{ __('One controller, no box, small scratch on the lid') }}">
                            <div class="form-text">
                                {{ __('Half of what the price is based on. Worth writing down while it is in your hand.') }}
                            </div>
                            @error('condition_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label for="category_id" class="form-label">{{ __('Category') }}</label>
                                <select id="category_id" name="category_id" class="form-select">
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}"
                                            @selected(old('category_id', $defaultCategory?->id) == $category->id)>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="bought_at" class="form-label">{{ __('Date') }}</label>
                                <input id="bought_at" type="date" name="bought_at" dir="ltr" required
                                       value="{{ old('bought_at', now()->toDateString()) }}"
                                       class="form-control @error('bought_at') is-invalid @enderror">
                                @error('bought_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">{{ __('Who you bought it from') }}</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label for="seller_name" class="form-label">{{ __('Name') }}</label>
                                <input id="seller_name" name="seller_name" value="{{ old('seller_name') }}" required
                                       class="form-control @error('seller_name') is-invalid @enderror">
                                @error('seller_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-6">
                                <label for="seller_phone" class="form-label">{{ __('Phone') }}</label>
                                <input id="seller_phone" name="seller_phone" value="{{ old('seller_phone') }}" dir="ltr"
                                       class="form-control @error('seller_phone') is-invalid @enderror">
                                {{-- Matched on the phone, because that is what a
                                     person gives twice the same way. --}}
                                <div class="form-text">
                                    {{ __('If you have bought from this number before, it is the same person — what you still owe them adds up.') }}
                                </div>
                                @error('seller_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">{{ __('The money') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="cost" class="form-label">{{ __('Price agreed') }}</label>
                            <div class="input-group">
                                <input id="cost" type="number" step="1" min="0" name="cost" dir="ltr" required
                                       value="{{ old('cost') }}" data-numpad
                                       class="form-control text-end @error('cost') is-invalid @enderror">
                                <span class="input-group-text">{{ setting('currency', 'IQD') }}</span>
                            </div>
                            <div class="form-text">
                                {{ __('This is the cost of this one item, and the cost its profit is measured against.') }}
                            </div>
                            @error('cost')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="amount_paid" class="form-label">{{ __('Paid now') }}</label>
                            <div class="input-group">
                                <input id="amount_paid" type="number" step="1" min="0" name="amount_paid" dir="ltr"
                                       value="{{ old('amount_paid', 0) }}" data-numpad
                                       class="form-control text-end @error('amount_paid') is-invalid @enderror">
                                <span class="input-group-text">{{ setting('currency', 'IQD') }}</span>
                            </div>
                            <div class="form-text">
                                {{ __('Anything left over stays as what you owe them, and can be paid later.') }}
                            </div>
                            @error('amount_paid')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="payment_method" class="form-label">{{ __('Method') }}</label>
                            <select id="payment_method" name="payment_method" class="form-select">
                                <option value="cash" @selected(old('payment_method') === 'cash')>{{ __('Cash') }}</option>
                                <option value="bank" @selected(old('payment_method') === 'bank')>{{ __('Bank') }}</option>
                                <option value="transfer" @selected(old('payment_method') === 'transfer')>{{ __('Transfer') }}</option>
                            </select>
                        </div>

                        <hr>

                        <div class="mb-3">
                            <label for="sale_price" class="form-label">{{ __('Asking price') }}</label>
                            <div class="input-group">
                                <input id="sale_price" type="number" step="1" min="0" name="sale_price" dir="ltr" required
                                       value="{{ old('sale_price') }}" data-numpad
                                       class="form-control text-end @error('sale_price') is-invalid @enderror">
                                <span class="input-group-text">{{ setting('currency', 'IQD') }}</span>
                            </div>
                            <div class="form-text">
                                {{ __('What the cart will suggest. You can still change it when it sells.') }}
                            </div>
                            @error('sale_price')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <button class="btn btn-primary w-100" data-submitting-text="{{ __('Saving…') }}">
                            <i class="bi bi-bag-check me-1"></i>{{ __('Buy it') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
