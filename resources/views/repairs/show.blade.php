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

    {{-- ⚠️ The price the customer is holding, against the price it now is.
         Neither replaces the other — the paper in their hand says the first. --}}
    @if($repair->priceDrift() !== 0)
        <div class="alert alert-warning">
            <div class="fw-semibold mb-1">{{ __('The price has moved since it was agreed') }}</div>
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
            <div class="small mt-1">{{ __('Tell the customer before they collect.') }}</div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header">{{ __('The phone') }}</div>
                <div class="card-body">
                    <dl class="row mb-0 g-2">
                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('What is wrong') }}</dt>
                        <dd class="col-sm-8 mb-0">{{ $repair->fault }}</dd>

                        @if($repair->identifier)
                            <dt class="col-sm-4 text-secondary fw-normal">{{ __('IMEI or serial') }}</dt>
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
                                <td class="money">{{ money($item->lineTotal(), false, $lens) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr>
                            <th colspan="4">{{ __('The job comes to') }}</th>
                            <th class="money">{{ money($repair->total(), false, $lens) }}</th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
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
                        @if($repair->isAccepted())
                            <div class="small text-secondary mt-2">
                                {{ __('Customer accepted :amount on :date', [
                                    'amount' => money($repair->accepted_total, in: $lens),
                                    'date' => $repair->accepted_at->format(setting('date_format', 'Y-m-d')),
                                ]) }}
                            </div>
                        @endif
                    </div>

                    @can('repairs.edit')
                        @if($repair->canBeModified() && $repair->status !== App\Models\Repair::STATUS_RETURNED)

                            {{-- ⚠️ Acceptance is its own action, not a status. It is
                                 what freezes the agreed price and the warranty, and
                                 it is what the printed ticket comes from. --}}
                            @if(! $repair->isAccepted())
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

                                    <button class="btn btn-primary w-100" data-submitting-text="{{ __('Saving…') }}">
                                        <i class="bi bi-check2-circle me-1"></i>{{ __('Customer accepts — print the ticket') }}
                                    </button>
                                    <div class="form-text">
                                        {{ __('Fixes the price and the warranty on their ticket, and starts the work.') }}
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

                            @if($repair->isAccepted())
                                <hr>

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
