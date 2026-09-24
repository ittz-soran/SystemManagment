@extends('layouts.app')

@section('title', __('Find anything'))

@section('actions')
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    {{-- The box. It stays at the top in every state, so a second question is
         asked where the first one was. --}}
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('find') }}" class="row g-2">
                <div class="col-12 col-md-9">
                    <label for="find-q" class="visually-hidden">{{ __('Find anything') }}</label>

                    {{-- ⚠️ The panel hangs off THIS wrapper, not off the grid
                         column. A column carries the row's gutter as padding,
                         so a `w-100` panel inside one is a gutter wider than
                         the box it belongs to and sits half a centimetre
                         proud of it. Same structure as the topbar's. --}}
                    <div class="position-relative">
                        {{-- ⚠️ `data-english-digits` puts this box on app.js's
                             list of fields a Kurdish or Arabic keyboard may be
                             pointed at, so ٤٥٠٠ becomes 4500 as it is typed.
                             The server translates them again on arrival — a
                             scanner, a paste and a typed character do not all
                             take the same road in. --}}
                        <input id="find-q" type="search" name="q" value="{{ $term }}"
                               class="form-control form-control-lg" data-english-digits
                               autocomplete="off" role="combobox"
                               aria-expanded="false" aria-controls="find-suggestions"
                               placeholder="{{ __('Name, code, barcode, phone, address or a document number') }}">

                        {{-- What the box thinks you might mean, while you type. --}}
                        <div id="find-suggestions" class="app-search-results dropdown-menu w-100 p-0 overflow-auto"
                             role="listbox" aria-label="{{ __('Find anything') }}"
                             data-url="{{ route('find.suggest') }}"
                             data-empty="{{ __('Nothing found.') }}"></div>
                    </div>
                </div>
                <div class="col-12 col-md-3 d-grid">
                    <button class="btn btn-primary btn-lg">
                        <i class="bi bi-search me-1"></i>{{ __('Find it') }}
                    </button>
                </div>
            </form>

            @if($term === '')
                <p class="text-secondary small mt-3 mb-0" id="find-hint">
                    {{ __('Scan a barcode, type part of a name, a phone number, or the number printed on any document. Kurdish, Arabic and Persian digits are read as English ones.') }}
                </p>
            @endif
        </div>
    </div>

    {{-- ─── One product: everything the shop knows about it ───────────────── --}}
    @if($product)
        @include('find.product')

    @elseif($term !== '')
        @php
            $nothing = $products->isEmpty() && $people->isEmpty() && $documents->isEmpty();
        @endphp

        @if($nothing)
            <div class="card">
                <x-empty-state icon="search" :message="__('Nothing matches :term.', ['term' => $term])" />
            </div>
        @else
            @if($products->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header">{{ __('Products') }}</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 table-cards">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="money">{{ __('On the shelf') }}</th>
                                <th class="money">{{ __('Price') }}</th>
                                <th class="text-end"></th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($products as $found)
                                <tr>
                                    <td class="list-card-title">
                                        {{ $found->name }}
                                        <div class="small text-secondary" dir="ltr">{{ $found->sku }}</div>
                                    </td>
                                    <td class="money" data-label="{{ __('On the shelf') }}">
                                        {{ $found->tracksStock() ? qty($found->quantity, $found->unit) : '—' }}
                                    </td>
                                    <td class="money" data-label="{{ __('Price') }}">{{ money($found->sale_price, false, $lens) }}</td>
                                    <td class="list-card-actions text-end">
                                        <a href="{{ route('find', ['product' => $found->id]) }}"
                                           class="btn btn-sm btn-primary">{{ __('This one') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if($people->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header">{{ __('People') }}</div>
                    <ul class="list-group list-group-flush">
                        @foreach($people as $person)
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                <span>
                                    <i class="bi bi-{{ $person['icon'] }} me-2 text-secondary"></i>
                                    <a href="{{ $person['url'] }}" class="text-decoration-none">{{ $person['name'] }}</a>
                                    <span class="small text-secondary">· {{ $person['note'] }}</span>
                                </span>
                                @if($person['phone'])
                                    <span class="app-code small text-secondary">{{ $person['phone'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($documents->isNotEmpty())
                <div class="card">
                    <div class="card-header">{{ __('Documents') }}</div>
                    <ul class="list-group list-group-flush">
                        @foreach($documents as $document)
                            <li class="list-group-item">
                                <i class="bi bi-{{ $document['icon'] }} me-2 text-secondary"></i>
                                <a href="{{ $document['url'] }}" class="text-decoration-none app-code">{{ $document['number'] }}</a>
                                <span class="small text-secondary">· {{ $document['note'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    @endif
@endsection
