@extends('layouts.app')

@section('title', $swap->document_no)
@section('subheading')
    {{ $swap->swapped_at->format(setting('date_format', 'Y-m-d')) }}
    @if($swap->sale?->customer)
        · {{ $swap->sale->customer->displayName() }}
    @endif
    @if($swap->sale)
        · @can('sales.view')
            {{-- The number is the way back to the document this swap came from. --}}
            {!! __('against :document', ['document' => '<a href="'.e(route('sales.show', $swap->sale)).'" class="text-decoration-none" dir="ltr">'.e($swap->sale->document_no).'</a>']) !!}
        @else
            {{ __('against :document', ['document' => $swap->sale->document_no]) }}
        @endcan
    @endif
@endsection

@section('back')
    <x-back-link :to="route('swaps.index')" :label="__('Swaps')" remember="swaps" permission="swaps.view" />
@endsection

{{--
    ⚠️ **The same document page as SRT and PRT** — Soran, 2026-09-25: *"make all
    three purchase-returns, sale-returns, swaps have same designs or same like
    one"*. Number, date and party in the heading · Print and Delete as the
    actions · the lines table with its total on the left · two cards on the
    right, the money and where it went · the note · the history.
--}}
@section('actions')
    <a href="{{ route('swaps.print', $swap) }}" class="btn btn-outline-secondary" target="_blank">
        <i class="bi bi-printer me-1"></i>{{ __('Print') }}
    </a>

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
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">{{ __('What was swapped') }}</div>

                {{-- ⚠️ One row, and a table anyway. A swap is always exactly one
                     product — that is what makes it a swap rather than a return
                     — but the reader arriving from an SRT or a PRT should not
                     have to learn a second way of reading the same fact. --}}
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th class="money">{{ __('Quantity') }}</th>
                            {{-- ⚠️ Short headings, full sentences in the card
                                 beside it. "Given back for the faulty one" over
                                 a column ran the table past the edge of its own
                                 card on a laptop, and a clipped heading reads as
                                 a broken page rather than a wide one. --}}
                            <th class="money">{{ __('Replacement') }}</th>
                            <th class="money">{{ __('Given back') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td>
                                <a href="{{ route('products.show', $swap->product) }}" class="text-decoration-none">
                                    {{ $swap->product->name }}
                                </a>
                                <div class="small text-secondary" dir="ltr">{{ $swap->product->sku }}</div>
                            </td>
                            <td class="money">{{ qty($swap->quantity, $swap->product->unit) }}</td>
                            <td class="money">{{ money($swap->replacement_cost, false, $lens) }}</td>
                            <td class="money">−{{ money($swap->faulty_cost, false, $lens) }}</td>
                        </tr>
                        </tbody>
                        <tfoot>
                        @php $difference = $swap->cost(); @endphp
                        {{-- ⚠️ The sign is read here rather than printed. A
                             replacement off a dearer layer leaves the shop out
                             of pocket, and off a cheaper one leaves it ahead —
                             and "Out of pocket: −4,000" would say the opposite
                             of what it means. --}}
                        <tr class="fw-semibold">
                            <td colspan="3" class="text-end">
                                {{ $difference < 0 ? __('Ahead by') : __('Out of pocket') }}
                            </td>
                            <td class="money">{{ money(abs($difference), false, $lens) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- The one thing a reader is most likely to doubt, said on the
                     document itself rather than left to be worked out. --}}
                <div class="card-footer small text-secondary">
                    <i class="bi bi-info-circle me-1"></i>
                    {{ __('The invoice was not changed. The customer bought it and still owns it — what changed is which unit they have.') }}
                </div>

                @can('swaps.edit')
                    {{-- ⚠️ **The note is written on the row; the quantity is
                         not.** A note is a sentence ABOUT the handover, so it
                         can be corrected in place. A quantity IS the handover:
                         changing it undoes the whole swap and lays it down
                         again at the new figure, which is why it asks for the
                         key that undoes one — see SwapService::update. --}}
                    <div class="card-footer">
                        <form action="{{ route('swaps.update', $swap) }}" method="POST" class="row g-2 align-items-end">
                            @csrf
                            @method('PATCH')

                            @if($changeState['allowed'])
                                <div class="col-6 col-sm-auto">
                                    <label for="quantity" class="form-label small text-secondary mb-1">
                                        {{ __('How many') }}
                                    </label>
                                    <div class="input-group input-group-sm flex-nowrap">
                                        <input id="quantity" type="number" name="quantity" dir="ltr"
                                               class="form-control text-end" style="min-width: 4rem"
                                               min="1" max="{{ $mostItCouldBe }}" step="1"
                                               value="{{ old('quantity', $swap->quantity) }}">
                                        @if($swap->product?->unit)
                                            <span class="input-group-text">{{ $swap->product->unit }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <div class="col-12 col-sm">
                                <label for="note" class="form-label small text-secondary mb-1">{{ __('Note') }}</label>
                                <input id="note" name="note" class="form-control form-control-sm" maxlength="500"
                                       value="{{ old('note', $swap->note) }}"
                                       placeholder="{{ __('Not charging, screen dead, dead on arrival…') }}">
                            </div>
                            <div class="col-12 col-sm-auto">
                                <button class="btn btn-sm btn-outline-primary">
                                    {{ $changeState['allowed'] ? __('Save changes') : __('Save note') }}
                                </button>
                            </div>
                        </form>

                        @if($changeState['allowed'])
                            <p class="form-text mb-0 mt-2">
                                {{ __('Changing how many undoes this swap and does it again at the new figure: the units come back, the supplier is re-billed, and the replacement leaves the shelf afresh. At most :count.', ['count' => number_format($mostItCouldBe)]) }}
                            </p>
                        @else
                            {{-- ⚠️ Shown with its reason, not hidden. The
                                 reason is the thing that says what to do
                                 instead — and "the note only" with no
                                 explanation reads as a system that has
                                 forgotten how. --}}
                            <p class="form-text mb-0 mt-2">
                                <i class="bi bi-lock me-1"></i>{{ $changeState['reason'] }}
                            </p>
                        @endif
                    </div>
                @elseif($swap->note)
                    <div class="card-footer small">
                        <span class="text-secondary">{{ __('Note') }}:</span> {{ $swap->note }}
                    </div>
                @endcan
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header">{{ __('The money') }}</div>
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
    </div>

    {{-- ⚠️ There IS a history now, and 2026-09-25 is why: a swap's quantity can
         be corrected, so "who changed it, and to what" is a real question. Until
         that day a swap was written once and never edited, and this card would
         have read "nothing recorded yet" forever — which says the opposite of
         the truth. `Swap` is in ActivityObserver's list for exactly this. --}}
    <x-record-history :for="$swap" />
@endsection
