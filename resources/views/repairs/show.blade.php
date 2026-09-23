@extends('layouts.app')

@section('title', $repair->device)
@section('subheading', $repair->document_no.' · '.$repair->customer->name)

@section('back')
    <x-back-link :to="route('repairs.index')" :label="__('Repairs')" remember="repairs" permission="repairs.view" />
@endsection

@section('actions')
    @if($repair->isAccepted())
        <a href="{{ route('repairs.ticket', $repair) }}" target="_blank" rel="noopener"
           class="btn btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>{{ __('Ticket') }}
        </a>
    @endif

    @can('repairs.edit')
        @if($repair->canBeModified())
            <a href="{{ route('repairs.edit', $repair) }}" class="btn btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
            </a>
        @endif
    @endcan
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    @if(! $repair->canBeModified())
        {{-- Section 9b: a lock is normal, not an error. Quiet, and it says what
             would unlock it. --}}
        <div class="alert alert-secondary d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-lock"></i>
            <span>
                {{ __('Collected and paid for.') }}
                @if($repair->sale)
                    <x-document-link :document="$repair->sale" :kind="false" />
                @endif
            </span>
        </div>
    @endif

    {{-- ⚠️ Not "the price moved" — "the customer has not agreed to this".
         Soran's PS4: agreed at 8,000, then the drive turned out to be failing,
         and the rule in his shop is to telephone before touching it. So this
         says what to do, and collection is refused until it is done. --}}
    @if($repair->lastApproval() && $repair->needsApproval())
        <div class="alert alert-warning">
            <div class="fw-semibold mb-1">{{ __('The customer has not agreed to this yet') }}</div>
            <div class="d-flex flex-wrap gap-4">
                <span>{{ __('On their ticket') }}: <span class="money fw-semibold">{{ money($repair->accepted_total, false, $lens) }}</span></span>
                <span>{{ __('Now') }}: <span class="money fw-semibold">{{ money($repair->total(), false, $lens) }}</span></span>
                <span>
                    {{ __('Difference') }}:
                    <span class="money fw-semibold {{ $repair->priceDrift() > 0 ? 'text-danger' : 'text-success' }}">
                        {{ $repair->priceDrift() > 0 ? '+' : '−' }}{{ money(abs($repair->priceDrift()), false, $lens) }}
                    </span>
                </span>
            </div>
            <div class="small mt-1">{{ __('Call them, then record below that they accepted. It cannot be collected until you do.') }}</div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header">{{ __('The device') }}</div>
                <div class="card-body">
                    <dl class="row mb-0 g-2">
                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('What is wrong') }}</dt>
                        <dd class="col-sm-8 mb-0">{{ $repair->fault }}</dd>

                        @if($repair->identifier)
                            <dt class="col-sm-4 text-secondary fw-normal">{{ __('Serial or IMEI') }}</dt>
                            <dd class="col-sm-8 mb-0"><span class="app-code" dir="ltr">{{ $repair->identifier }}</span></dd>
                        @endif

                        @if($repair->condition_note)
                            <dt class="col-sm-4 text-secondary fw-normal">{{ __('How it looked') }}</dt>
                            <dd class="col-sm-8 mb-0">{{ $repair->condition_note }}</dd>
                        @endif

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Taken in') }}</dt>
                        <dd class="col-sm-8 mb-0" dir="ltr">
                            {{ $repair->received_at->format(setting('date_format', 'Y-m-d')) }}
                        </dd>

                        @if($repair->promised_for)
                            <dt class="col-sm-4 text-secondary fw-normal">{{ __('Promised') }}</dt>
                            <dd class="col-sm-8 mb-0 {{ $repair->isOverdue() ? 'text-danger fw-semibold' : '' }}" dir="ltr">
                                {{ $repair->promised_for->format(setting('date_format', 'Y-m-d')) }}
                                @if($repair->isOverdue()) · {{ __('Late') }} @endif
                            </dd>
                        @endif

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Who is doing it') }}</dt>
                        <dd class="col-sm-8 mb-0">
                            @if($repair->technician)
                                {{ $repair->technician->name }}
                                @if($repair->technician->phone)
                                    <span class="text-secondary" dir="ltr">· {{ $repair->technician->phone }}</span>
                                @endif
                            @else
                                <span class="text-secondary">{{ __('Not decided yet') }}</span>
                            @endif
                        </dd>

                        @if($repair->note)
                            <dt class="col-sm-4 text-secondary fw-normal">{{ __('Note') }}</dt>
                            <dd class="col-sm-8 mb-0">{{ $repair->note }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            @php
                /* ⚠️ Whether cost is shown at all is `cost_seen()`'s answer and
                   nothing else — Section 4's one door for every cost figure in
                   this system. A reader set to `markup 20` sees every number
                   here 20% above the real one, and the profit below is worked
                   out from THAT, or the true cost would be one subtraction
                   away from a masked one. */
                $costTotal = cost_seen($cost['cost']);
                $showCost = $costTotal !== null;
            @endphp

            <div class="card">
                <div class="card-header">{{ __('What the job needs') }}</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('Part or work') }}</th>
                            <th>{{ __('Warranty') }}</th>
                            <th class="money">{{ __('Qty') }}</th>
                            <th class="money">{{ __('Price') }}</th>
                            {{-- ⚠️ `d-none d-md-table-cell`, and measured before it
                                 was written: six columns are 65px wider than a
                                 390px phone, so adding this one pushed the Total
                                 clean off the screen and took the footer figures
                                 with it. The cost PER LINE is a bench question
                                 asked at a desk; the cost of the job is the one
                                 asked anywhere, and it is under the table where
                                 no column can hide it. --}}
                            @if($showCost)
                                <th class="money d-none d-md-table-cell">
                                    {{ $cost['real'] ? __('Cost') : __('Cost now') }}
                                </th>
                            @endif
                            <th class="money">{{ __('Total') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($repair->items as $item)
                            <tr>
                                <td>
                                    {{ $item->product->name }}
                                    <div class="small text-secondary app-code">{{ $item->product->sku }}</div>
                                </td>
                                <td class="small">
                                    @if($item->warranty_days === null)
                                        <span class="text-secondary">—</span>
                                    @else
                                        {{ trans_choice('{0}Same day|{1}:count day|[2,*]:count days', $item->warranty_days, ['count' => $item->warranty_days]) }}
                                        @php
                                            $ends = $repair->warrantyEndsOn($item);
                                        @endphp
                                        @if($ends)
                                            <div class="text-secondary" dir="ltr">
                                                {{ __('until :date', ['date' => $ends->format(setting('date_format', 'Y-m-d'))]) }}
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                <td class="money">{{ number_format($item->quantity) }}</td>
                                <td class="money">{{ money($item->unit_price, false, $lens) }}</td>
                                @if($showCost)
                                    <td class="money text-secondary d-none d-md-table-cell">
                                        {{ money((int) cost_seen($cost['lines'][$item->id] ?? 0), false, $lens) }}
                                    </td>
                                @endif
                                <td class="money">{{ money($item->lineTotal(), false, $lens) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr>
                            <th colspan="4">{{ __('The job comes to') }}</th>
                            @if($showCost)
                                <th class="d-none d-md-table-cell"></th>
                            @endif
                            <th class="money">{{ money($repair->total(), false, $lens) }}</th>
                        </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- ⚠️ Cost and profit live UNDER the table, not in it.
                     In the table they needed a colspan, and a colspan is what
                     put them in a column a phone cannot reach. Here they are two
                     plain lines that are read the same on any screen.

                     Before collection this is a forecast off the FIFO queue as
                     it stands today; after collection it is the cost the sale
                     actually consumed, which is the figure Profit & Loss uses.
                     The wording says which, rather than leaving the shop to
                     guess how firm the number is. --}}
                {{-- ⚠️ A job handed back unrepaired earns nothing, and must not
                     be shown as though it might. The lines are still listed —
                     they are what the shop had set aside — but they were never
                     charged and never will be, and a "Profit on this job" line
                     against them is a number that will never arrive. --}}
                @if($repair->status === App\Models\Repair::STATUS_RETURNED)
                    <div class="card-body border-top py-2 small text-secondary">
                        {{ __('Handed back unrepaired. Nothing was charged, and the parts never left the shelf.') }}
                    </div>
                @elseif($showCost)
                    <div class="card-body border-top py-2">
                        <div class="d-flex justify-content-between gap-2 small">
                            <span class="text-secondary">
                                {{ $cost['real']
                                    ? __('What it cost the shop')
                                    : __('What it would cost the shop today') }}
                                @unless($cost['real'])
                                    · {{ __('nothing has left the shelf yet') }}
                                @endunless
                            </span>
                            <span class="money text-secondary">{{ money($costTotal, false, $lens) }}</span>
                        </div>

                        @if($cost['short'] > 0)
                            <div class="small text-warning-emphasis mt-1">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                {{ trans_choice(
                                    '{1}:count part is not in stock yet, and is counted at what it last cost.'
                                    .'|[2,*]:count parts are not in stock yet, and are counted at what they last cost.',
                                    $cost['short'], ['count' => number_format($cost['short'])]) }}
                            </div>
                        @endif

                        {{-- ⚠️ What came back comes off, or this line claims a
                             profit the shop never made. A customer who brings the
                             television back and takes his money is an ordinary
                             afternoon: the board is on the shelf again with its
                             cost reversed, and what the shop kept is the labour.
                             The per-person report nets refunds off too — one
                             afternoon must not have two answers in one system. --}}
                        @if($cost['refunded'] > 0)
                            <div class="d-flex justify-content-between gap-2 small">
                                <span class="text-secondary">{{ __('Given back to the customer') }}</span>
                                <span class="money text-secondary">
                                    − {{ money($cost['refunded'], false, $lens) }}
                                </span>
                            </div>
                        @endif

                        <div class="d-flex justify-content-between gap-2 fw-semibold mt-1">
                            <span>{{ __('Profit on this job') }}</span>
                            <span class="money">
                                {{ money($repair->total() - $cost['refunded'] - $costTotal, false, $lens) }}
                            </span>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">{{ __('Where it is up to') }}</div>
                <div class="card-body">
                    @php
                        $tone = match ($repair->status) {
                            App\Models\Repair::STATUS_READY => 'success',
                            App\Models\Repair::STATUS_WORKING => 'warning',
                            App\Models\Repair::STATUS_QUOTED => 'info',
                            App\Models\Repair::STATUS_COLLECTED => 'secondary',
                            App\Models\Repair::STATUS_RETURNED => 'danger',
                            default => 'light',
                        };
                    @endphp

                    <div class="mb-3">
                        <span class="badge text-bg-{{ $tone }} fs-6">{{ __(Str::headline($repair->status)) }}</span>
                        @if($repair->approvals->isNotEmpty())
                            {{-- Every yes, in order. A job agreed twice is the
                                 ordinary case, not an exception. --}}
                            <div class="small mt-2">
                                <div class="text-secondary">{{ __('What the customer agreed to') }}</div>
                                @foreach($repair->approvals as $approval)
                                    <div class="d-flex justify-content-between gap-2 border-bottom py-1">
                                        <span>
                                            <span dir="ltr">{{ $approval->approved_at->format(setting('date_format', 'Y-m-d')) }}</span>
                                            <span class="text-secondary">
                                                · {{ $approval->channel === App\Models\RepairApproval::CHANNEL_PHONE ? __('on the phone') : __('at the shop') }}
                                            </span>
                                            @if($approval->note)
                                                <div class="text-secondary">{{ $approval->note }}</div>
                                            @endif
                                        </span>
                                        <span class="money fw-semibold">{{ money($approval->total, false, $lens) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @can('repairs.edit')
                        @if($repair->canBeModified() && $repair->status !== App\Models\Repair::STATUS_RETURNED)

                            {{-- ⚠️ Acceptance is its own action, not a status. It is
                                 what freezes the agreed price and the warranty, and
                                 it is what the printed ticket comes from. --}}
                            @if($repair->needsApproval())
                                <form method="POST" action="{{ route('repairs.accept', $repair) }}" class="mb-3" data-guard-submit>
                                    @csrf
                                    <label for="accept-technician" class="form-label small">{{ __('Who will do it') }}</label>
                                    <select id="accept-technician" name="technician_id" class="form-select form-select-sm mb-2">
                                        <option value="">{{ __('Not decided yet') }}</option>
                                        @foreach($technicians as $technician)
                                            <option value="{{ $technician->id }}" @selected($repair->technician_id == $technician->id)>
                                                {{ $technician->name }}@if($technician->phone) · {{ $technician->phone }}@endif
                                            </option>
                                        @endforeach
                                    </select>

                                    {{-- ⚠️ How they were told is the evidence. The first yes is
                                         across the counter; a second, after a fault is found
                                         mid-repair, is a telephone call — and that call is what
                                         settles it when the customer is holding an older ticket. --}}
                                    <label for="channel" class="form-label small">{{ __('How did they agree?') }}</label>
                                    <select id="channel" name="channel" class="form-select form-select-sm mb-2">
                                        <option value="counter">{{ __('Here at the shop') }}</option>
                                        <option value="phone" @selected($repair->lastApproval() !== null)>{{ __('On the phone') }}</option>
                                    </select>

                                    <input name="note" class="form-control form-control-sm mb-2" maxlength="500"
                                           placeholder="{{ __('Hard drive failing, needs replacing') }}">

                                    <button class="btn btn-primary w-100" data-submitting-text="{{ __('Saving…') }}">
                                        <i class="bi bi-check2-circle me-1"></i>{{ __('Customer accepts — print the ticket') }}
                                    </button>
                                    <div class="form-text">
                                        {{ __('Records what they agreed to and fixes the warranty on their ticket.') }}
                                    </div>
                                </form>
                            @else
                                <form method="POST" action="{{ route('repairs.status', $repair) }}" class="mb-3" data-guard-submit>
                                    @csrf @method('PATCH')
                                    <label for="next-status" class="form-label small">{{ __('Move it along') }}</label>
                                    <div class="d-flex gap-2">
                                        <select id="next-status" name="status" class="form-select form-select-sm">
                                            @foreach([App\Models\Repair::STATUS_WORKING, App\Models\Repair::STATUS_READY] as $value)
                                                <option value="{{ $value }}" @selected($repair->status === $value)>
                                                    {{ __(Str::headline($value)) }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-sm btn-outline-secondary">{{ __('Set') }}</button>
                                    </div>
                                </form>
                            @endif

                            {{-- ⚠️ Collecting is a SALE, and the route has always
                                 demanded `sales.create` for it. The button did not:
                                 a bench hand was shown the green "make the invoice"
                                 button, pressed it, and got a 403 with the customer
                                 standing there. The books were never at risk; the
                                 screen was a trap that looked like it was working,
                                 which Section 4 refuses everywhere else. --}}
                            @if($repair->isAccepted() && ! $repair->needsApproval())
                                <hr>

                                @cannot('sales.create')
                                    <div class="small text-secondary mb-3">
                                        <i class="bi bi-info-circle me-1"></i>
                                        {{ __('Ready for the customer. Collecting it is a sale, so somebody at the counter takes the money and makes the invoice.') }}
                                    </div>
                                @endcannot

                                @can('sales.create')
                                <form method="POST" action="{{ route('repairs.collect', $repair) }}" data-guard-submit>
                                    @csrf
                                    <div class="mb-2">
                                        <label for="amount_paid" class="form-label small">{{ __('Paid now') }}</label>
                                        <x-money-input name="amount_paid" :lens="$lens" :min="0"
                                                       :value="$repair->total()" data-numpad="{{ __('Paid now') }}" />
                                        @error('amount_paid')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    </div>

                                    <div class="mb-3">
                                        <label for="payment_method" class="form-label small">{{ __('Method') }}</label>
                                        <select id="payment_method" name="payment_method" class="form-select form-select-sm">
                                            <option value="cash">{{ __('Cash') }}</option>
                                            <option value="bank">{{ __('Bank') }}</option>
                                            <option value="transfer">{{ __('Transfer') }}</option>
                                        </select>
                                    </div>

                                    <button class="btn btn-success w-100" data-submitting-text="{{ __('Saving…') }}">
                                        <i class="bi bi-bag-check me-1"></i>{{ __('Customer collects — make the invoice') }}
                                    </button>
                                    <div class="form-text">
                                        {{ __('Takes the parts out of stock at their real cost and makes an ordinary invoice.') }}
                                    </div>
                                </form>
                                @endcan

                                <hr>
                            @endif

                            <form method="POST" action="{{ route('repairs.hand-back', $repair) }}">
                                @csrf @method('PATCH')
                                <label for="why" class="form-label small">{{ __('Or hand it back unrepaired') }}</label>
                                <input id="why" name="why" class="form-control form-control-sm mb-2" maxlength="500"
                                       placeholder="{{ __('Board is water damaged, not worth it') }}">
                                <button class="btn btn-sm btn-outline-danger w-100">{{ __('Hand back') }}</button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    </div>
@endsection
