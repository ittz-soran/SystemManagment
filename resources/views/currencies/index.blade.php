@extends('layouts.app')

@section('title', __('Currencies'))
@section('subheading', __('What you can type and read prices in. The books stay in :code.', ['code' => $base->code]))

@section('back')
    <a href="{{ route('settings.edit') }}" class="text-decoration-none">
        <i class="bi bi-arrow-left app-back-arrow me-1"></i>{{ __('Settings') }}
    </a>
@endsection

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#currency-modal">
        <i class="bi bi-plus-lg me-1"></i>{{ __('New currency') }}
    </button>
@endsection

@section('content')
    {{-- Section 2b: a currency is a lens, not a second set of books. Said once,
         at the top, because everything below only makes sense once a reader
         knows that nothing here changes what is stored. --}}
    <div class="alert alert-light border d-flex gap-2 align-items-start">
        <i class="bi bi-info-circle mt-1"></i>
        <div class="small">
            {{ __('Every amount is stored in :code, whatever it was typed in. A currency here changes what a screen shows you and what its boxes expect — never what is written down.', ['code' => $base->code]) }}
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>{{ __('Currency') }}</th>
                    <th class="money">{{ __('Decimals') }}</th>
                    <th class="money">{{ __('Rate') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($currencies as $currency)
                    @php($isBase = $currency->code === $base->code)

                    <tr class="{{ $currency->is_active ? '' : 'opacity-50' }}">
                        <td>
                            <span class="fw-medium app-code">{{ $currency->code }}</span>
                            @if($isBase)
                                <span class="badge text-bg-primary ms-1">{{ __('Base') }}</span>
                            @endif
                            <div class="small text-secondary">
                                {{ $currency->name }}@if($currency->symbol) · <span class="app-code">{{ $currency->symbol }}</span>@endif
                            </div>
                        </td>

                        <td class="money text-secondary">{{ $currency->decimals }}</td>

                        <td class="money">
                            @if($isBase)
                                {{-- Not a number anybody chooses: it is 10^decimals by
                                     definition, and a dinar is one dinar. --}}
                                <span class="text-secondary">—</span>
                            @else
                                <span class="app-code">{{ $currency->rateAsTyped() }}</span>
                                <div class="small text-secondary text-nowrap">
                                    {{ __('1 :code = :rate :base', [
                                        'code' => $currency->code,
                                        'rate' => $currency->rateAsTyped(),
                                        'base' => $base->code,
                                    ]) }}
                                </div>
                            @endif
                        </td>

                        <td>
                            <span class="badge text-bg-{{ $currency->is_active ? 'success' : 'secondary' }}">
                                {{ $currency->is_active ? __('Active') : __('Hidden from new entries') }}
                            </span>
                        </td>

                        <td class="text-end">
                            @php($blocker = $blockers[$currency->code] ?? null)

                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#edit-{{ $currency->id }}">
                                    <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
                                </button>

                                @unless($isBase)
                                    {{-- ⚠️ Moving the books REINTERPRETS every stored
                                         figure, so the button exists only while nothing
                                         has been recorded. Shown disabled with the reason
                                         rather than hidden: a missing button is a
                                         shopkeeper searching other screens for it. --}}
                                    <form method="POST" action="{{ route('currencies.base', $currency) }}"
                                          class="d-inline"
                                          onsubmit="return confirm(@js(__('Keep the books in :code from now on? Nothing already recorded is converted — this only works because nothing has been.', ['code' => $currency->code])))">
                                        @csrf
                                        <button class="btn btn-outline-secondary rounded-0"
                                                @disabled($recorded !== null)
                                                title="{{ $recorded !== null
                                                    ? __('The books already have :what recorded.', ['what' => $recorded])
                                                    : __('Keep the books in :code', ['code' => $currency->code]) }}">
                                            <i class="bi bi-journal-check me-1"></i>{{ __('Make base') }}
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('currencies.destroy', $currency) }}"
                                          class="d-inline"
                                          onsubmit="return confirm(@js(__('Remove :code?', ['code' => $currency->code])))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger"
                                                @disabled($blocker !== null)
                                                title="{{ $blocker ?? __('Remove :code', ['code' => $currency->code]) }}"
                                                aria-label="{{ __('Remove :code', ['code' => $currency->code]) }}">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                @endunless
                            </div>

                            @if($blocker && ! $isBase)
                                <div class="small text-secondary mt-1">{{ $blocker }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- One editing dialog per row, because the fields differ: the base has no
         rate to set and cannot be switched off. --}}
    @foreach($currencies as $currency)
        @php($isBase = $currency->code === $base->code)

        <div class="modal fade" id="edit-{{ $currency->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" method="POST"
                      action="{{ route('currencies.update', $currency) }}" data-guard-submit>
                    @csrf
                    @method('PUT')

                    <div class="modal-header">
                        <h5 class="modal-title">
                            {{ __('Edit :code', ['code' => $currency->code]) }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="{{ __('Close') }}"></button>
                    </div>

                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label for="name-{{ $currency->id }}" class="form-label">{{ __('Name') }}</label>
                            <input id="name-{{ $currency->id }}" name="name" class="form-control"
                                   value="{{ old('name', $currency->name) }}" required>
                        </div>

                        <div>
                            <label for="symbol-{{ $currency->id }}" class="form-label">{{ __('Symbol') }}</label>
                            <input id="symbol-{{ $currency->id }}" name="symbol" class="form-control" dir="ltr"
                                   value="{{ old('symbol', $currency->symbol) }}">
                            <div class="form-text">{{ __('Shown after a figure. The code is used when this is blank.') }}</div>
                        </div>

                        <div>
                            <label for="decimals-{{ $currency->id }}" class="form-label">
                                {{ __('Decimal places') }}
                            </label>
                            <select id="decimals-{{ $currency->id }}" name="decimals" class="form-select">
                                @foreach([0, 2, 3] as $option)
                                    <option value="{{ $option }}" @selected(old('decimals', $currency->decimals) == $option)>
                                        {{ trans_choice('{0}None — whole units only|{1}:count place|[2,*]:count places', $option, ['count' => $option]) }}
                                    </option>
                                @endforeach
                            </select>

                            @if($isBase)
                                {{-- ⚠️ Section 2b: on the base this IS the
                                     redenomination. Nothing is written and no row
                                     moves — the stored integer stops counting
                                     dinars and starts counting fils. Which is also
                                     why it is safe to offer: set it back and every
                                     screen reads exactly as it did. --}}
                                <div class="form-text text-warning">
                                    {{ __('This is what every stored amount counts. With three places a figure reading 250,000 today reads 250 instead, on every screen at once. Nothing is written and no record moves — setting it back puts every figure exactly where it was.') }}
                                </div>
                            @else
                                <div class="form-text">
                                    {{ __('How this currency is written. Two for a dollar, none for a whole-unit currency.') }}
                                </div>
                            @endif
                        </div>

                        @unless($isBase)
                            <div>
                                <label for="rate-{{ $currency->id }}" class="form-label">
                                    {{ __('1 :code is worth', ['code' => $currency->code]) }}
                                </label>
                                <div class="input-group">
                                    <input id="rate-{{ $currency->id }}" name="rate" class="form-control" dir="ltr"
                                           inputmode="decimal"
                                           value="{{ old('rate', $currency->rateAsTyped()) }}" required>
                                    <span class="input-group-text">{{ $base->code }}</span>
                                </div>
                                <div class="form-text">
                                    {{ __('Up to :places decimal places. Today’s rate — nothing already recorded changes when you edit it.', ['places' => $places]) }}
                                </div>
                            </div>

                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" name="is_active"
                                       id="active-{{ $currency->id }}" @checked($currency->is_active)>
                                <label class="form-check-label" for="active-{{ $currency->id }}">
                                    {{ __('Offer this currency on screens') }}
                                </label>
                            </div>
                        @else
                            {{-- ⚠️ Section 2b: this field is the redenomination. Changing
                                 it reprices every screen at once, and the entry half is
                                 not built — a shop could read 15.5 and not type it. --}}
                            <div class="alert alert-light border small mb-0">
                                {{ __('This is the currency the books are kept in, so it has no rate of its own and cannot be switched off.') }}
                            </div>
                        @endunless
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            {{ __('Cancel') }}
                        </button>
                        <button class="btn btn-primary">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    <div class="modal fade" id="currency-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" action="{{ route('currencies.store') }}" method="POST" data-guard-submit>
                @csrf

                <div class="modal-header">
                    <h5 class="modal-title">{{ __('New currency') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="modal-body d-flex flex-column gap-3">
                    <div class="row g-3">
                        <div class="col-5">
                            <label for="code" class="form-label">{{ __('Code') }}</label>
                            <input id="code" name="code" class="form-control app-code @error('code') is-invalid @enderror"
                                   dir="ltr" maxlength="8" placeholder="USD" value="{{ old('code') }}" required>
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-7">
                            <label for="new-name" class="form-label">{{ __('Name') }}</label>
                            <input id="new-name" name="name" class="form-control @error('name') is-invalid @enderror"
                                   placeholder="{{ __('US Dollar') }}" value="{{ old('name') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-5">
                            <label for="new-symbol" class="form-label">{{ __('Symbol') }}</label>
                            <input id="new-symbol" name="symbol" class="form-control" dir="ltr"
                                   placeholder="$" value="{{ old('symbol') }}">
                        </div>
                        <div class="col-7">
                            <label for="decimals" class="form-label">{{ __('Decimal places') }}</label>
                            <select id="decimals" name="decimals" class="form-select @error('decimals') is-invalid @enderror">
                                @foreach([0, 2, 3] as $places)
                                    <option value="{{ $places }}" @selected(old('decimals', 2) == $places)>
                                        {{ trans_choice('{0}None — whole units only|{1}:count place|[2,*]:count places', $places, ['count' => $places]) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('decimals')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div>
                        <label for="new-rate" class="form-label">{{ __('1 unit is worth') }}</label>
                        <div class="input-group">
                            <input id="new-rate" name="rate" class="form-control @error('rate') is-invalid @enderror"
                                   dir="ltr" inputmode="decimal" placeholder="1320" value="{{ old('rate') }}" required>
                            <span class="input-group-text">{{ $base->code }}</span>
                            @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        {{ __('Cancel') }}
                    </button>
                    <button class="btn btn-primary">{{ __('Save') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
