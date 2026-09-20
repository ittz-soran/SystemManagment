@extends('layouts.print')

@section('title', $title)
@section('doc-title', $title)
@section('doc-date', __('As at :date', ['date' => $report['as_at']->format(setting('date_format', 'Y-m-d'))]))

@section('content')
    {{-- ⚠️ A balance cannot be aged — it is a running total with no dates in
         it. Every column here is built from documents, each placed by the age
         of its own date, which is why this page says so rather than leaving
         the reader to wonder why it does not match a statement. --}}
    <p class="small mb-3">
        {{ __('Counted from each document’s own date. Money that belongs to no document — an opening balance — cannot be aged, and is shown separately.') }}
    </p>

    @if($report['rows']->isEmpty())
        <p class="text-center py-4">{{ __('Nobody owes anything.') }}</p>
    @else
        <table class="table table-sm">
            <thead>
            <tr>
                <th>{{ $nameLabel }}</th>
                @foreach(App\Services\AgedDebtService::labels() as $label)
                    <th class="money">{{ $label }}</th>
                @endforeach
                <th class="money">{{ __('Aged total') }}</th>
                <th class="money">{{ __('Not aged') }}</th>
                <th class="money">{{ __('Balance') }}</th>
            </tr>
            </thead>

            <tbody>
            @foreach($report['rows'] as $row)
                <tr>
                    <td>
                        {{ $row->person->name }}
                        @if($row->person->phone)
                            <div class="small" dir="ltr">{{ $row->person->phone }}</div>
                        @endif
                    </td>

                    @foreach($row->buckets as $index => $amount)
                        {{-- The oldest column is the one the eye should find
                             first: it is the money least likely to arrive. --}}
                        <td class="money {{ $index === count($row->buckets) - 1 && $amount > 0 ? 'fw-semibold' : '' }}">
                            {{ $amount === 0 ? '—' : money($amount, false) }}
                        </td>
                    @endforeach

                    <td class="money fw-semibold">{{ money($row->outstanding, false) }}</td>
                    <td class="money">{{ $row->unaged === 0 ? '—' : money($row->unaged, false) }}</td>
                    <td class="money">{{ money($row->balance, false) }}</td>
                </tr>
            @endforeach
            </tbody>

            <tfoot>
            <tr>
                <th>{{ __('Total') }}</th>
                @foreach($report['totals'] as $amount)
                    <th class="money">{{ money($amount, false) }}</th>
                @endforeach
                <th class="money">{{ money($report['outstanding'], false) }}</th>
                <th class="money">{{ $report['unaged'] === 0 ? '—' : money($report['unaged'], false) }}</th>
                <th class="money">{{ money($report['balances'], false) }}</th>
            </tr>
            </tfoot>
        </table>

        {{-- ⚠️ Worth reading rather than worth hiding. An opening balance
             legitimately sits outside the columns; anything else in this figure
             means the documents and the ledger disagree, which is a thing to
             know about the books rather than about the debt. --}}
        @if($report['unaged'] !== 0)
            <p class="small mt-3">
                {{ __('“Not aged” is money on the ledger that belongs to no document. An opening balance is the ordinary reason. Anything else means the documents and the ledger disagree — worth a look at Settings → Data check.') }}
            </p>
        @endif
    @endif
@endsection
