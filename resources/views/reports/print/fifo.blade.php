@extends('layouts.print')

@section('title', __('FIFO audit'))
@section('doc-title', __('FIFO audit'))
@section('doc-date', $everything
    ? __('Everything the shop has ever sold')
    : $from->format(setting('date_format', 'Y-m-d')).' — '.$to->format(setting('date_format', 'Y-m-d')))

@section('content')
    @unless($everything)
        @include('reports.print._period')
    @else
        <div class="d-flex justify-content-between align-items-baseline mb-3">
            <div>
                <span class="small text-uppercase">{{ __('Period') }}</span>
                <span class="fw-semibold ms-2">{{ __('Everything the shop has ever sold') }}</span>
            </div>
            <div class="small" dir="ltr">
                {{ __('Printed') }} {{ now()->format(setting('date_format', 'Y-m-d')) }}
            </div>
        </div>
    @endunless

    <p class="small">
        {{ __('Stock is costed oldest layer first. This sheet replays every movement the shop has recorded, in the order things happened, and lists any sale that took a layer while an older one still had stock on the same shelf.') }}
    </p>

    {{-- ─── The answer, first ──────────────────────────────────────────────── --}}
    @if($summary['lines'] === 0)
        <div class="border rounded p-3 my-3">
            <div class="h6 mb-1">{{ __('Nothing to report.') }}</div>
            <p class="small mb-0">
                {{ __('Every sale took the oldest layer it could reach. The cost on every invoice is the cost the shop actually paid for those units.') }}
            </p>
        </div>
    @else
        <div class="border rounded p-3 my-3">
            <div class="h6 mb-2">
                {{ trans_choice('{1}One line took the wrong layer|[2,*]:count lines took the wrong layer', $summary['lines'], ['count' => number_format($summary['lines'])]) }}
            </div>
            <table class="table table-sm mb-2">
                <tbody>
                <tr>
                    <td>{{ __('Lines affected') }}</td>
                    <td class="money">{{ number_format($summary['lines']) }}</td>
                </tr>
                <tr>
                    <td>{{ __('Units') }}</td>
                    <td class="money">{{ number_format($summary['units']) }}</td>
                </tr>
                <tr>
                    <td>{{ __('Products') }}</td>
                    <td class="money">{{ number_format($summary['products']) }}</td>
                </tr>
                <tr class="fw-bold border-top">
                    <td>
                        {{ $summary['difference'] >= 0
                            ? __('Charged MORE than the oldest layer cost, so reported profit is lower than the truth by')
                            : __('Charged LESS than the oldest layer cost, so reported profit is higher than the truth by') }}
                    </td>
                    <td class="money">{{ money(abs($summary['difference']), false) }}</td>
                </tr>
                </tbody>
            </table>

            {{-- ⚠️ **Said out loud when the column does not add to the
                 headline** — Soran, 2026-09-26. Two of his lines named the same
                 last unit of the same older layer and both were charged its
                 3,000, so the sheet told him 6,650 where the truth was 3,650.
                 The rows are each true and stay; the total is the honest one;
                 and the reader is told why adding the column gives a third
                 number rather than being left to wonder which to believe. --}}
            @if($summary['listed'] !== $summary['difference'])
                <p class="small mb-2">
                    {{ __('Adding the column below comes to :listed, which is more than the shop lost. Some of these lines passed the SAME units of the same older layer — only the first of them could have taken those units, so the figure above counts them once.', [
                        'listed' => money(abs($summary['listed']), false),
                    ]) }}
                </p>
            @endif

            <p class="small mb-0">
                {{ __('Nothing has been changed. Re-costing a sale that has already been reported would move profit between months that have been read and perhaps closed; in this shop a correction is always a new forward document, never an edit to what happened.') }}
            </p>
        </div>

        {{-- ─── Every one of them ─────────────────────────────────────────── --}}
        <div class="h6 border-bottom pb-1 mb-2 mt-4">{{ __('Every line, newest first') }}</div>
        <table class="table table-sm">
            <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Document') }}</th>
                <th>{{ __('Product') }}</th>
                <th class="money">{{ __('Quantity') }}</th>
                <th>{{ __('Took') }}</th>
                <th>{{ __('Should have taken') }}</th>
                <th class="money">{{ __('Difference') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($findings as $row)
                <tr>
                    <td dir="ltr">{{ $row->occurred_at->format(setting('date_format', 'Y-m-d')) }}</td>
                    <td>
                        <span class="app-code">
                            {{ $documents[$row->reference_type.':'.$row->reference_id]
                                ?? strtoupper($row->reference_type).' #'.$row->reference_id }}
                        </span>
                    </td>
                    <td>{{ $row->product }}</td>
                    <td class="money">{{ number_format($row->units) }}</td>
                    <td>
                        <span class="app-code">#{{ $row->took_batch }}</span>
                        <span class="money">{{ money($row->took_cost, false) }}</span>
                        <span class="small d-block" dir="ltr">{{ $row->took_received->format(setting('date_format', 'Y-m-d')) }}</span>
                    </td>
                    <td>
                        <span class="app-code">#{{ $row->older_batch }}</span>
                        <span class="money">{{ money($row->older_cost, false) }}</span>
                        <span class="small d-block" dir="ltr">
                            {{ $row->older_received->format(setting('date_format', 'Y-m-d')) }}
                            · {{ __(':count left', ['count' => number_format($row->older_left)]) }}
                        </span>
                    </td>
                    <td class="money fw-semibold">{{ money($row->difference, false) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="fw-bold border-top">
                <td colspan="3">{{ __('Together') }}</td>
                <td class="money">{{ number_format($summary['units']) }}</td>
                <td colspan="2"></td>
                {{-- ⚠️ The COLUMN's own sum, which is what a reader adding the
                     rows will get. The headline above is the honest figure, and
                     the note up there explains the gap when there is one. --}}
                <td class="money">{{ money($summary['listed'], false) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif

    <div class="h6 border-bottom pb-1 mb-2 mt-4">{{ __('How to read this sheet') }}</div>
    <ul class="small">
        <li>{{ __('The shelf is not wrong. The same units left the shop either way — what differs is which layer each sale was charged to, and therefore what profit each one reported.') }}</li>
        <li>{{ __('A supplier return is never listed. It comes off the batch that purchase created, on purpose: those goods go back to that supplier, not the oldest ones you happen to hold.') }}</li>
        <li>{{ __('A layer in another room is never counted as skipped. The till sells one room, so stock in the back was never a choice it had.') }}</li>
        <li>{{ __('A sale entered late can appear here without anything being wrong: read in date order, paperwork caught up a week afterwards can show a sale that skipped a layer which had not yet been entered.') }}</li>
    </ul>
@endsection
