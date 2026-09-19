@extends('layouts.app')

@section('title', __('Second-hand'))
@section('subheading', __('One row for one thing: what was paid, what is asked, what it made'))

@section('actions')
    <x-currency-lens :label="__('Read in')" />

    @can('suppliers.view')
        <a href="{{ route('second-hand.sellers') }}" class="btn btn-outline-secondary">
            <i class="bi bi-people me-1"></i>{{ __('Sellers') }}
        </a>
    @endcan
    @can('purchases.create')
        <a href="{{ route('second-hand.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>{{ __('Buy an item') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    @php
        $cards = [
            [
                'label' => __('Items held'),
                'value' => number_format($figures['held']),
                'note' => __('right now'),
                'tone' => '',
            ],
            [
                'label' => __('Money tied up in them'),
                'value' => money_if($figures['held_value'] !== null, $figures['held_value'], in: $lens),
                'note' => __('right now'),
                'tone' => '',
            ],
            [
                'label' => __('Expected profit'),
                'value' => money_if($figures['expected'] !== null, $figures['expected'], in: $lens),
                'note' => __('if they sell at the asking price'),
                'tone' => 'text-secondary',
            ],
            [
                'label' => __('Bought'),
                'value' => number_format($figures['bought']),
                'note' => __('for :amount', ['amount' => money_if($figures['spent'] !== null, $figures['spent'], in: $lens)]),
                'tone' => '',
            ],
            [
                'label' => __('Sold'),
                'value' => number_format($figures['sold']),
                'note' => null,
                'tone' => '',
            ],
            [
                'label' => __('Made'),
                'value' => money_if($figures['made'] !== null, $figures['made'], in: $lens),
                'note' => __('what those sales actually made'),
                'tone' => match (true) {
                    $figures['made'] === null => 'text-secondary',
                    $figures['made'] >= 0 => 'text-success',
                    default => 'text-danger',
                },
            ],
        ];
    @endphp

    {{-- KPI cards --}}
    <div class="row g-2 mb-3">
        @foreach($cards as $card)
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body py-2">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <div class="text-secondary small fw-medium">{{ $card['label'] }}</div>
                            {{-- subtle dot indicator --}}
                            <span class="visually-hidden">{{ $card['label'] }}</span>
                        </div>

                        <div class="fs-5 fw-semibold money {{ $card['tone'] }} mt-1">
                            {{ $card['value'] }}
                        </div>

                        @if(!empty($card['note']))
                            <div class="text-secondary" style="font-size: .75rem; line-height: 1.2;">
                                {{ $card['note'] }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Owed banner --}}
    @if($figures['owed_to_sellers'] > 0)
        <div class="alert alert-warning d-flex align-items-center justify-content-between py-2 shadow-sm">
            <span class="d-flex align-items-center">
                <i class="bi bi-cash-coin me-2"></i>
                {{ __('Still owed to the people you bought from') }}
            </span>

            <span class="d-flex align-items-center gap-3">
                <span class="fw-semibold money">{{ money($figures['owed_to_sellers'], in: $lens) }}</span>
                @can('suppliers.view')
                    <a href="{{ route('second-hand.sellers') }}" class="small text-decoration-none">
                        {{ __('Who') }}
                    </a>
                @endcan
            </span>
        </div>
    @endif

    {{-- Filter bar --}}
    <form method="GET" class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label for="search" class="form-label small text-secondary">{{ __('Item') }}</label>
                    <input id="search" type="search" name="search" value="{{ request('search') }}"
                           class="form-control form-control-sm"
                           placeholder="{{ __('Name, stock code or condition') }}">
                </div>

                <div class="col-md-2">
                    <label for="status" class="form-label small text-secondary">{{ __('Status') }}</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="all" @selected($status === 'all')>
                            {{ __('All') }} ({{ number_format($counts['all']) }})
                        </option>
                        <option value="in_stock" @selected($status === 'in_stock')>
                            {{ __('In stock') }} ({{ number_format($counts['in_stock']) }})
                        </option>
                        <option value="sold" @selected($status === 'sold')>
                            {{ __('Sold') }} ({{ number_format($counts['sold']) }})
                        </option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="from" class="form-label small text-secondary">{{ __('From') }}</label>
                    <input id="from" type="date" name="from" dir="ltr" value="{{ $from->toDateString() }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label for="to" class="form-label small text-secondary">{{ __('To') }}</label>
                    <input id="to" type="date" name="to" dir="ltr" value="{{ $to->toDateString() }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary flex-fill">
                        {{ __('Filter') }}
                    </button>

                    <a href="{{ route('second-hand.index') }}"
                       class="btn btn-sm btn-outline-secondary"
                       title="{{ __('Clear') }}">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </div>
        </div>
    </form>

    @if($items->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <x-empty-state icon="box-seam"
                               :message="__('Nothing here yet. Buying a second-hand item creates it and records the purchase in one step.')" />
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th class="text-secondary">{{ __('Item') }}</th>
                            <th class="text-secondary">{{ __('Bought from') }}</th>
                            <th class="text-secondary">{{ __('History') }}</th>
                            <th class="money text-secondary">{{ __('Paid for it') }}</th>
                            <th class="money text-secondary">{{ __('Asking') }}</th>
                            <th class="money text-secondary">{{ __('Profit') }}</th>
                        </tr>
                        </thead>

                        <tbody>
                        @foreach($items as $item)
                            @php
                                $purchase = $purchases[$item->id] ?? null;
                                $sale = $sales[$item->id] ?? null;
                                $sold = $item->quantity <= 0 && $sale;

                                $cost = cost_seen((int) ($item->stockBatches->first()->unit_cost ?? $item->purchase_price));
                            @endphp

                            <tr class="border-bottom">
                                <td>
                                    <div class="d-flex flex-column">
                                        <a href="{{ route('products.show', $item) }}"
                                           class="text-decoration-none fw-medium">
                                            {{ $item->name }}
                                        </a>

                                        @if($sold)
                                            <span class="badge text-bg-secondary mt-1">{{ __('Sold') }}</span>
                                        @else
                                            <span class="badge text-bg-success mt-1">{{ __('In stock') }}</span>
                                        @endif

                                        <div class="small text-secondary app-code mt-1">{{ $item->sku }}</div>

                                        @if($item->condition_note)
                                            <div class="small text-secondary">{{ $item->condition_note }}</div>
                                        @endif
                                    </div>
                                </td>

                                <td class="small">
                                    @if($item->acquiredFrom)
                                        <x-document-link :document="$item->acquiredFrom" :kind="false" />
                                        @if($item->acquiredFrom->phone)
                                            <div class="text-secondary" dir="ltr">{{ $item->acquiredFrom->phone }}</div>
                                        @endif
                                    @else
                                        <span class="text-secondary">—</span>
                                    @endif
                                </td>

                                <td class="small">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-arrow-down-left text-success"></i>
                                        <span dir="ltr" class="text-secondary">
                                            {{ ($purchase?->purchase->purchase_date ?? $item->created_at)->format(setting('date_format', 'Y-m-d')) }}
                                        </span>
                                        @if($purchase?->purchase)
                                            <x-document-link :document="$purchase->purchase" :kind="false" />
                                        @endif
                                    </div>

                                    @if($sold)
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            <i class="bi bi-arrow-up-right text-danger"></i>
                                            <span dir="ltr" class="text-secondary">
                                                {{ $sale->sale->sale_date->format(setting('date_format', 'Y-m-d')) }}
                                            </span>
                                            <x-document-link :document="$sale->sale" :kind="false" />
                                        </div>
                                    @else
                                        <div class="text-secondary mt-1">
                                            <i class="bi bi-hourglass-split me-1"></i>
                                            {{ trans_choice('{0}today|{1}:count day held|[2,*]:count days held',
                                                (int) $item->created_at->diffInDays(now()),
                                                ['count' => number_format((int) $item->created_at->diffInDays(now()))]) }}
                                        </div>
                                    @endif
                                </td>

                                <td class="money">
                                    {{ money_if($cost !== null, $cost, false, $lens) }}
                                </td>

                                <td class="money">
                                    {{ money($item->sale_price, false, $lens) }}
                                </td>

                                <td class="money fw-semibold">
                                    @if($sold)
                                        @php($profit = $cost === null ? null : $sale->unit_price - $cost)

                                        @if($profit === null)
                                            <span class="text-secondary">{{ hidden_money() }}</span>
                                        @else
                                            <div class="d-flex flex-column">
                                                <span class="{{ $profit >= 0 ? 'text-success' : 'text-danger' }}">
                                                    {{ $profit >= 0 ? '+' : '−' }}{{ money(abs($profit), false, $lens) }}
                                                </span>
                                                <div class="small text-secondary fw-normal mt-1">
                                                    {{ __('sold for :amount', ['amount' => money($sale->unit_price, in: $lens)]) }}
                                                </div>
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-secondary fw-normal">
                                            {{ __('if asked: :amount', [
                                                'amount' => $cost === null
                                                    ? hidden_money()
                                                    : money($item->sale_price - $cost, false, $lens),
                                            ]) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $items->links() }}
                </div>
            </div>
        </div>
    @endif
@endsection