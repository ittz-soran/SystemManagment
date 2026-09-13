{{--
    What a converted figure is, said plainly.

    ⚠️ It is an ESTIMATE, and it must say so. The shop's books are in one
    currency; these figures are that money divided by today's rate, and today's
    rate is not the rate anything was recorded at. A dollar total presented
    without that sentence is a figure somebody will quote to a supplier.
--}}
@props(['lens'])

@if($lens)
    <div class="alert alert-light border d-flex gap-2 align-items-start small no-print">
        <i class="bi bi-info-circle mt-1"></i>
        <div>
            {{ __('Shown in :code at today’s rate of :rate — an estimate. The books are kept in :base and nothing here is what was recorded.', [
                'code' => $lens->code,
                'rate' => $lens->rateAsTyped(),
                'base' => \App\Support\Money::base()->code,
            ]) }}
        </div>
    </div>
@endif
