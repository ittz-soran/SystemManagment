{{--
    What a dollar purchase still owes, in dollars — PROJECT_DOC Section 6. The
    dinars beside the remainder are at TODAY's rate and say so: an estimate,
    never saved, because the dinars are only known on the day they are paid.

    $owed      from PaymentController::owedInDollars
    $purchase  the purchase
--}}
<div class="card">
    <div class="card-header">{{ __('This document') }}</div>
    <ul class="list-group list-group-flush">
        <li class="list-group-item d-flex justify-content-between">
            <span class="text-secondary">{{ __('Total') }}</span>
            <span class="text-end">
                <span class="money d-block">{{ $purchase->inItsCurrency($owed['total']) }}</span>
                <span class="small text-secondary">
                    <span class="money">{{ money($purchase->grand_total, in: $lens) }}</span> · {{ __('at :rate', ['rate' => number_format($owed['purchaseRate'])]) }}
                </span>
            </span>
        </li>
        @if($owed['returned'] > 0)
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-secondary">{{ __('Returned') }}</span>
                <span class="money">−{{ $purchase->inItsCurrency($owed['returned']) }}</span>
            </li>
        @endif
        @if($owed['paid'] !== 0)
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-secondary">{{ __('Already paid') }}</span>
                <span class="money">{{ $purchase->inItsCurrency($owed['paid']) }}</span>
            </li>
        @endif
        <li class="list-group-item d-flex justify-content-between fw-semibold">
            <span>{{ __('Remaining') }}</span>
            <span class="money">{{ $purchase->inItsCurrency($owed['due']) }}</span>
        </li>
        @if($owed['due'] > 0)
            <li class="list-group-item d-flex justify-content-between small text-secondary">
                <span>{{ __('At today\'s rate, an estimate') }}</span>
                <span><span class="money">≈ {{ money(App\Support\Money::toBase($owed['due'], $owed['currency']), in: $lens) }}</span> · {{ __('at :rate', ['rate' => number_format($owed['todaysRate'])]) }}</span>
            </li>
        @endif
    </ul>
</div>
