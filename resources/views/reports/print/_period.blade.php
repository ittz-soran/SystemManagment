{{-- The period, said once, at the top of every printed report. A sheet that
     has left the printer cannot be asked what dates it covers. --}}
<div class="d-flex justify-content-between align-items-baseline mb-3">
    <div>
        <span class="small text-uppercase">{{ __('Period') }}</span>
        <span class="fw-semibold ms-2" dir="ltr">
            {{ $from->format(setting('date_format', 'Y-m-d')) }}
            —
            {{ $to->format(setting('date_format', 'Y-m-d')) }}
        </span>
    </div>
    <div class="small" dir="ltr">
        {{ __('Printed') }} {{ now()->format(setting('date_format', 'Y-m-d')) }}
    </div>
</div>
