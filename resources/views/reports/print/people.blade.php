@extends('layouts.print')

@section('title', $title)
@section('doc-title', $title)
@section('doc-date', $from->format(setting('date_format', 'Y-m-d')).' — '.$to->format(setting('date_format', 'Y-m-d')))

@section('content')
    @include('reports.print._period')

    {{-- What was traded is the period's. What is owed is not: a balance carries
         in from before these dates, and cutting it to fit would make the page
         disagree with what the person actually owes. --}}
    <p class="small mb-3">
        {{ __('Traded figures are for the period. The balance is what stands today.') }}
    </p>

    @if($people->isEmpty())
        <p class="text-center py-4">{{ __('Nobody traded in this period.') }}</p>
    @else
        <table class="table table-sm">
            <thead>
            <tr>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Phone') }}</th>
                <th class="money">{{ __('Documents') }}</th>
                <th class="money">{{ $tradeLabel }}</th>
                <th class="money">{{ __('Returned') }}</th>
                <th class="money">{{ __('Net') }}</th>
                <th class="money">{{ $owedLabel }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($people as $row)
                <tr>
                    <td>{{ $row->person instanceof App\Models\Customer ? $row->person->displayName() : $row->person->name }}</td>
                    <td dir="ltr" class="small">{{ $row->person->phone ?: '—' }}</td>
                    <td class="money">{{ number_format($row->documents) }}</td>
                    <td class="money">{{ money($row->traded, false) }}</td>
                    <td class="money">{{ $row->returned > 0 ? '− '.money($row->returned, false) : '—' }}</td>
                    <td class="money">{{ money($row->traded - $row->returned, false) }}</td>
                    <td class="money fw-semibold">{{ money($row->balance, false) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="fw-bold border-top">
                <td colspan="2">
                    {{ trans_choice('{1}:count person|[2,*]:count people', $people->count(), ['count' => number_format($people->count())]) }}
                </td>
                <td class="money">{{ number_format($people->sum('documents')) }}</td>
                <td class="money">{{ money($people->sum('traded'), false) }}</td>
                <td class="money">{{ money($people->sum('returned'), false) }}</td>
                <td class="money">{{ money($people->sum('traded') - $people->sum('returned'), false) }}</td>
                <td class="money">{{ money($people->sum('balance'), false) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif
@endsection
