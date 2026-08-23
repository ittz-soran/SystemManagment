@extends('layouts.app')

@section('title', __('Second-hand'))
@section('subheading', __('One row for one thing: what was paid, what is asked, what it made'))


@section('actions')
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
    {{-- Three about where things stand whatever period is being read, three
         about what happened between the dates chosen below. --}}
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
                'value' => money($figures['held_value'], false),
                'note' => __('right now'),
                'tone' => '',
            ],
            [
                'label' => __('Expected profit'),
                'value' => money($figures['expected'], false),
                'note' => __('if they sell at the asking price'),
                'tone' => 'text-secondary',
            ],
            [
                'label' => __('Bought'),
                'value' => number_format($figures['bought']),
                'note' => __('for :amount', ['amount' => money($figures['spent'], false)]),
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
                'value' => money($figures['made'], false),
                'note' => __('what those sales actually made'),
                'tone' => $figures['made'] >= 0 ? 'text-success' : 'text-danger',
            ],
        ];
    @endphp

    <div class="row g-2 mb-3">
        @foreach($cards as $card)
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card card-body h-100 py-2">
                    <div class="text-secondary small">{{ $card['label'] }}</div>
                    <div class="fs-5 fw-semibold money {{ $card['tone'] }}">{{ $card['value'] }}</div>
                    @if($card['note'])
                        <div class="text-secondary" style="font-size: .75rem">{{ $card['note'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Money the shop is holding that is not its own. It belongs beside the
         figures rather than inside them: it is owed whatever period is read. --}}
    @if($figures['owed_to_sellers'] > 0)
        <div class="alert alert-warning d-flex align-items-center justify-content-between py-2">
            <span>
                <i class="bi bi-cash-coin me-1"></i>
                {{ __('Still owed to the people you bought from') }}
            </span>
            <span class="d-flex align-items-center gap-3">
                <span class="fw-semibold money">{{ money($figures['owed_to_sellers']) }}</span>
                @can('suppliers.view')
                    <a href="{{ route('second-hand.sellers') }}" class="small">{{ __('Who') }}</a>
                @endcan
            </span>
        </div>
    @endif

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small">{{ __('Item') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm"
                       placeholder="{{ __('Name, stock code or condition') }}">
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small">{{ __('Status') }}</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    {{-- Counted, so an item that has been sold reads as moved
                         rather than missing. --}}
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
                <label for="from" class="form-label small">{{ __('From') }}</label>
                <input id="from" type="date" name="from" dir="ltr" value="{{ $from->toDateString() }}"
                       class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label for="to" class="form-label small">{{ __('To') }}</label>
                <input id="to" type="date" name="to" dir="ltr" value="{{ $to->toDateString() }}"
                       class="form-control form-control-sm">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary flex-fill">{{ __('Filter') }}</button>
                <a href="{{ route('second-hand.index') }}" class="btn btn-sm btn-outline-secondary"
                   title="{{ __('Clear') }}"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    @if($items->isEmpty())
        <x-empty-state icon="box-seam"
                       :message="__('Nothing here yet. Buying a second-hand item creates it and records the purchase in one step.')" />
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Item') }}</th>
                        <th>{{ __('Bought from') }}</th>
                        <th>{{ __('History') }}</th>
                        <th class="money">{{ __('Paid for it') }}</th>
                        <th class="money">{{ __('Asking') }}</th>
                        <th class="money">{{ __('Profit') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($items as $item)
                        @php
                            $purchase = $purchases[$item->id] ?? null;
                            $sale = $sales[$item->id] ?? null;
                            $sold = $item->quantity <= 0 && $sale;

                            // What it cost is the batch, not the product row: the
                            // batch is the money that actually left the till and
                            // the cost FIFO charges the sale.
                            $cost = (int) ($item->stockBatches->first()->unit_cost ?? $item->purchase_price);
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('products.show', $item) }}" class="text-decoration-none fw-medium">
                                    {{ $item->name }}
                                </a>
                                @if($sold)
                                    <span class="badge text-bg-secondary">{{ __('Sold') }}</span>
                                @else
                                    <span class="badge text-bg-success">{{ __('In stock') }}</span>
                                @endif
                                <div class="small text-secondary" dir="ltr">{{ $item->sku }}</div>
                                @if($item->condition_note)
                                    <div class="small text-secondary">{{ $item->condition_note }}</div>
                                @endif
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
                            {{-- The item's whole life in one cell: the day it
                                 came in and on which document, the day it left
                                 and on which. What a second-hand book is for. --}}
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
                                    {{-- How long the shop's money has been sitting in it. --}}
                                    <div class="text-secondary mt-1">
                                        <i class="bi bi-hourglass-split"></i>
                                        {{ trans_choice('{0}today|{1}:count day held|[2,*]:count days held',
                                            (int) $item->created_at->diffInDays(now()),
                                            ['count' => number_format((int) $item->created_at->diffInDays(now()))]) }}
                                    </div>
                                @endif
                            </td>
                            <td class="money">{{ money($cost, false) }}</td>
                            <td class="money">{{ money($item->sale_price, false) }}</td>
                            {{-- The whole point of the row: this item's own
                                 money. Not an average, not a share of anything —
                                 what was paid for this one thing and what it
                                 sold for. --}}
                            <td class="money fw-semibold">
                                @if($sold)
                                    @php($profit = $sale->unit_price - $cost)
                                    <span class="{{ $profit >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $profit >= 0 ? '+' : '−' }}{{ money(abs($profit), false) }}
                                    </span>
                                    <div class="small text-secondary fw-normal">
                                        {{ __('sold for :amount', ['amount' => money($sale->unit_price, false)]) }}
                                    </div>
                                @else
                                    <span class="text-secondary fw-normal">
                                        {{ __('if asked: :amount', [
                                            'amount' => money($item->sale_price - $cost, false),
                                        ]) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $items->links() }}</div>
    @endif
@endsection
