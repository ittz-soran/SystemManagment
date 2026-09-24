@extends('layouts.app')

@section('title', $swap->document_no)
@section('subheading')
    {{ $swap->swapped_at->format(setting('date_format', 'Y-m-d')) }}
    @if($swap->sale?->customer)
        · {{ $swap->sale->customer->displayName() }}
    @endif
@endsection

@section('back')
    <x-back-link :to="route('swaps.index')" :label="__('Swaps')" remember="swaps" permission="swaps.view" />
@endsection

@section('actions')
    @can('swaps.delete')
        {{-- ⚠️ Undoing a swap un-bills the supplier and puts the replacement
             back on the shelf. That only works while the units are still where
             the swap left them, so the button says why when they are not
             rather than failing after it is pressed. --}}
        @if(! $deleteState['allowed'])
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $deleteState['reason'] }}">
                <button class="btn btn-outline-danger" disabled>
                    <i class="bi bi-trash me-1"></i>{{ __('Delete swap') }}
                </button>
            </span>
        @else
            <form action="{{ route('swaps.destroy', $swap) }}" method="POST"
                  onsubmit="return confirm(@js(__('Delete :document? The replacement goes back on the shelf, the supplier is billed no more, and the invoice line can be returned again.', [
                      'document' => $swap->document_no,
                  ])))">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>{{ __('Delete swap') }}
                </button>
            </form>
        @endif
    @endcan
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header">{{ __('What was swapped') }}</div>
                <div class="card-body">
                    <div class="fs-5 fw-semibold">{{ $swap->product->name }}</div>
                    <div class="small text-secondary mb-3" dir="ltr">{{ $swap->product->sku }}</div>

                    <div class="row g-3 small">
                        <div class="col-6 col-md-4">
                            <div class="text-secondary">{{ __('Quantity') }}</div>
                            <div>{{ qty($swap->quantity, $swap->product->unit) }}</div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-secondary">{{ __('Against') }}</div>
                            <div><x-document-link :document="$swap->sale" :kind="false" /></div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-secondary">{{ __('Done by') }}</div>
                            <div>{{ $swap->user?->name ?? '—' }}</div>
                        </div>
                    </div>
                </div>

                {{-- The one thing a reader is most likely to doubt, said on the
                     document itself rather than left to be worked out. --}}
                <div class="card-footer small text-secondary">
                    <i class="bi bi-info-circle me-1"></i>
                    {{ __('The invoice was not changed. The customer bought it and still owns it — what changed is which unit they have.') }}
                </div>

                @can('swaps.edit')
                    {{-- ⚠️ The note, and nothing else on this document, is
                         editable. A different quantity or a different line is a
                         different swap — see SwapController::update. --}}
                    <div class="card-footer">
                        <form action="{{ route('swaps.update', $swap) }}" method="POST" class="row g-2 align-items-end">
                            @csrf
                            @method('PATCH')
                            <div class="col-12 col-sm">
                                <label for="note" class="form-label small text-secondary mb-1">{{ __('Note') }}</label>
                                <input id="note" name="note" class="form-control form-control-sm" maxlength="500"
                                       value="{{ old('note', $swap->note) }}"
                                       placeholder="{{ __('Not charging, screen dead, dead on arrival…') }}">
                            </div>
                            <div class="col-12 col-sm-auto">
                                <button class="btn btn-sm btn-outline-primary">{{ __('Save note') }}</button>
                            </div>
                        </form>
                    </div>
                @elseif($swap->note)
                    <div class="card-footer small">
                        <span class="text-secondary">{{ __('Note') }}:</span> {{ $swap->note }}
                    </div>
                @endcan
            </div>

            <div class="card">
                <div class="card-header">{{ __('Where the faulty one went') }}</div>
                <div class="card-body">
                    @if($swap->purchaseReturn)
                        <div class="d-flex align-items-start gap-2">
                            <i class="bi bi-box-arrow-up-right mt-1"></i>
                            <div>
                                <div>
                                    {{ __('Back to :supplier', ['supplier' => $swap->purchaseReturn->purchase?->supplier?->name ?? __('the supplier')]) }}
                                    · <x-document-link :document="$swap->purchaseReturn" :kind="false" />
                                </div>
                                <div class="small text-secondary">
                                    {{ __('They give back what they were paid for it, so the faulty unit is not your loss.') }}
                                </div>
                            </div>
                        </div>
                    @else
                        {{-- Opening stock, or something carried in from another
                             room: no purchase behind it, so nobody to bill. --}}
                        <div class="text-secondary">
                            {{ __('It did not come from a purchase, so there was no supplier to send it back to. The shop carried it.') }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">{{ __('What it cost the shop') }}</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">{{ __('The replacement cost') }}</span>
                        <span class="money">{{ money($swap->replacement_cost, false, $lens) }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">{{ __('Given back for the faulty one') }}</span>
                        <span class="money">−{{ money($swap->faulty_cost, false, $lens) }}</span>
                    </div>

                    <hr>

                    @php $difference = $swap->cost(); @endphp

                    {{-- ⚠️ The sign is read here rather than printed. A
                         replacement off a dearer layer leaves the shop out of
                         pocket, and off a cheaper one leaves it ahead — and
                         "Out of pocket: −4,000" would say the opposite of what
                         it means. --}}
                    <div class="d-flex justify-content-between fw-semibold">
                        <span>{{ $difference < 0 ? __('Ahead by') : __('Out of pocket') }}</span>
                        <span class="money">{{ money(abs($difference), false, $lens) }}</span>
                    </div>

                    <div class="small text-secondary mt-2">
                        @if($difference === 0)
                            {{ __('Nothing: the replacement cost exactly what the faulty one did.') }}
                        @else
                            {{ __('The replacement came off a different layer than the faulty one, so the difference is what prices did in between.') }}
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ⚠️ No history card. activity_logs only holds what ActivityObserver is
         registered for, and a swap is not one of those — the same as a repair
         and a stock transfer. A card that always reads "nothing recorded yet"
         says the opposite of the truth, which is that a swap is not edited at
         all: it is written once, and its movements are the record. --}}
@endsection
