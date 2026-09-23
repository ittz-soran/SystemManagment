@extends('layouts.roll')

@section('title', __('Repair ticket').' '.$repair->document_no)

@section('content')
    <h1>{{ setting('shop_name', config('app.name')) }}</h1>

    @if(setting('shop_phone'))
        <div class="center small muted" dir="ltr">{{ setting('shop_phone') }}</div>
    @endif
    @if(setting('shop_address'))
        <div class="center small muted">{{ setting('shop_address') }}</div>
    @endif

    <hr>

    {{-- ⚠️ The barcode, high up where a thumb will not cover it and a photo
         will catch it — Soran: "have an barcode on customer ticket to scan it
         easy when came back to collect his device". It carries the ticket
         number and nothing else, so scanning it into the search box on the
         repairs list finds this job. --}}
    <div class="barcode">{!! $barcode !!}</div>
    <div class="center big" dir="ltr">{{ $repair->document_no }}</div>
    <div class="center small muted" dir="ltr">
        {{ $repair->received_at->format(setting('date_format', 'Y-m-d')) }}
    </div>

    <hr>

    <div class="row"><span class="muted">{{ __('Customer') }}</span>
        <span>{{ $repair->customer->name }}</span></div>
    @if($repair->customer->phone)
        <div class="row"><span class="muted">{{ __('Customer phone') }}</span>
            <span dir="ltr">{{ $repair->customer->phone }}</span></div>
    @endif

    <div class="row"><span class="muted">{{ __('Device') }}</span>
        <span>{{ $repair->device }}</span></div>
    @if($repair->identifier)
        <div class="row"><span class="muted">{{ __('Serial or IMEI') }}</span>
            <span dir="ltr">{{ $repair->identifier }}</span></div>
    @endif

    @if($repair->technician)
        <div class="row"><span class="muted">{{ __('Repaired by') }}</span>
            <span>{{ $repair->technician->name }}</span></div>
        @if($repair->technician->phone)
            <div class="row"><span class="muted"></span>
                <span dir="ltr" class="small">{{ $repair->technician->phone }}</span></div>
        @endif
    @endif

    @if($repair->promised_for)
        <div class="row"><span class="muted">{{ __('Ready by') }}</span>
            <span dir="ltr">{{ $repair->promised_for->format(setting('date_format', 'Y-m-d')) }}</span></div>
    @endif

    <hr>

    <div class="muted small">{{ __('What is wrong') }}</div>
    <div>{{ $repair->fault }}</div>

    @if($repair->condition_note)
        <div class="muted small" style="margin-top: 1.5mm">{{ __('How it looked when it came in') }}</div>
        <div class="small">{{ $repair->condition_note }}</div>
    @endif

    <hr>

    <table>
        <thead>
        <tr>
            <th class="small muted">{{ __('Part or work') }}</th>
            <th class="small muted num">{{ __('Total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($repair->items as $item)
            <tr>
                <td>
                    {{ $item->product->name }}
                    <div class="small muted">
                        @if($item->quantity > 1)
                            <span dir="ltr">{{ $item->quantity }} × {{ money($item->unit_price, false) }}</span>
                        @endif
                        @if($item->warranty_days !== null)
                            @if($item->quantity > 1) · @endif
                            {{ __('Warranty') }}
                            {{ trans_choice('{0}Same day|{1}:count day|[2,*]:count days', $item->warranty_days, ['count' => $item->warranty_days]) }}
                        @endif
                    </div>
                </td>
                <td class="num">{{ money($item->lineTotal(), false) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <hr>

    <div class="row big">
        <span>{{ __('Agreed total') }}</span>
        <span class="num">{{ money($repair->lastApproval()?->total ?? $repair->total()) }}</span>
    </div>

    {{-- ⚠️ Every yes, not only the last one. The customer may be holding a
         ticket printed at the first figure — Soran's PS4 went 8,000 at the
         counter and 43,000 by telephone — so a reprint shows both, and says
         which was agreed how. --}}
    @if($repair->approvals->count() > 1)
        <div class="small muted" style="margin-top: 2mm">{{ __('Agreed') }}:</div>
        @foreach($repair->approvals as $approval)
            <div class="row small">
                <span>
                    {{ $approval->approved_at->format(setting('date_format', 'Y-m-d')) }} ·
                    {{ $approval->channel === App\Models\RepairApproval::CHANNEL_PHONE ? __('by phone') : __('at the shop') }}
                </span>
                <span class="num">{{ money($approval->total, false) }}</span>
            </div>
        @endforeach
    @endif

    @if($repair->items->contains(fn ($item) => $item->warranty_days !== null))
        <hr>
        <div class="small">
            <strong>{{ __('Warranty') }}:</strong>
            {{ __('counted from the day you collect the device, and covers only the work and parts listed above.') }}
        </div>
    @endif

    <hr>

    <div class="center small">
        {{ __('Please bring this ticket to collect your device.') }}
    </div>
    <div class="center small muted">
        {{ __('A photo of it is fine — the barcode is what we scan.') }}
    </div>
@endsection
