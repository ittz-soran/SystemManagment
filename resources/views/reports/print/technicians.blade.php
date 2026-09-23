@extends('layouts.print')

@section('title', $title)
@section('doc-title', $title)
@section('doc-date', $from->format(setting('date_format', 'Y-m-d')).' — '.$to->format(setting('date_format', 'Y-m-d')))

@section('content')
    @include('reports.print._period')

    {{-- ⚠️ Two periods on one page, said out loud rather than left to be
         discovered. Work arrives on one date and is paid for on another, and a
         page that pretended otherwise would be wrong about one of them. --}}
    <p class="small mb-3">
        {{ __('Taken in counts jobs that arrived in this period. The money is from jobs collected in it, because that is when the sale happens. On the bench is what is open today.') }}
    </p>

    @if($people->isEmpty())
        <p class="text-center py-4">{{ __('No repair jobs in this period.') }}</p>
    @else
        @php
            /* Whether the money is shown at all is `cost_seen()`'s answer, taken
               from the first row — every row is masked the same way, because the
               mask belongs to the reader and not to the row. */
            $showCost = $people->first()->cost !== null;
        @endphp

        <table class="table table-sm">
            <thead>
            <tr>
                <th>{{ __('Repair person') }}</th>
                <th>{{ __('Phone') }}</th>
                <th class="money">{{ __('Taken in') }}</th>
                <th class="money">{{ __('On the bench') }}</th>
                <th class="money">{{ __('Collected') }}</th>
                <th class="money">{{ __('Charged') }}</th>
                @if($showCost)
                    <th class="money">{{ __('Cost') }}</th>
                    <th class="money">{{ __('Profit') }}</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach($people as $row)
                <tr>
                    <td>{{ $row->person->name }}</td>
                    <td dir="ltr" class="small">{{ $row->person->phone ?: '—' }}</td>
                    <td class="money">{{ number_format($row->takenIn) }}</td>
                    <td class="money">{{ $row->onBench > 0 ? number_format($row->onBench) : '—' }}</td>
                    <td class="money">{{ number_format($row->collected) }}</td>
                    <td class="money">{{ money($row->charged, false) }}</td>
                    @if($showCost)
                        <td class="money">{{ money($row->cost, false) }}</td>
                        <td class="money fw-semibold">{{ money($row->profit, false) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="fw-bold border-top">
                <td colspan="2">
                    {{ trans_choice('{1}:count person|[2,*]:count people', $people->count(), ['count' => number_format($people->count())]) }}
                </td>
                <td class="money">{{ number_format($people->sum('takenIn')) }}</td>
                <td class="money">{{ number_format($people->sum('onBench')) }}</td>
                <td class="money">{{ number_format($people->sum('collected')) }}</td>
                <td class="money">{{ money($people->sum('charged'), false) }}</td>
                @if($showCost)
                    <td class="money">{{ money($people->sum('cost'), false) }}</td>
                    <td class="money">{{ money($people->sum('profit'), false) }}</td>
                @endif
            </tr>
            </tfoot>
        </table>

        {{-- The jobs nobody has been given. Not a person, so not a row, but a
             number the shop wants to see on the same page as the people. --}}
        @if($unassigned > 0)
            <p class="small mt-3">
                {{ trans_choice(
                    '{1}:count job on the bench has not been given to anybody.'
                    .'|[2,*]:count jobs on the bench have not been given to anybody.',
                    $unassigned, ['count' => number_format($unassigned)]) }}
            </p>
        @endif
    @endif
@endsection
