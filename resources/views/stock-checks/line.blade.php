@extends('layouts.app')

@section('title', $product->name)
@section('subheading')
    {{ $check->document_no }} · {{ $check->scopeName() }}
@endsection

@section('back')
    <x-back-link :to="route('stock-checks.show', $check)" :label="$check->document_no" />
@endsection

{{--
    Checking one product — PROJECT_DOC Section 9, Stock check. Soran,
    2026-10-30: "show PD-17L-UK In stock, batches, movments... and Show Confirm
    (mean all correct not need movment), Skip (Show another product), Wrong
    (mean need correction by ADJ or return)".

    ⚠️ Confirm and Skip are one tap, not the two-second hold every other save
    is. A round is hundreds of products; the hold exists so a scanner's Enter
    cannot save a form by accident, and neither of these can be reached by one
    — the scan box is a search, and the count box belongs to Wrong, which keeps
    the hold because it can make an adjustment.

    `seen` goes back with every answer: the figure on this screen. If the
    stock moved since — a sale at the till — the answer is refused and the
    product shown again.
--}}
@section('content')
    @php
        // ⚠️ Skipped is still unanswered: a blind round keeps a skipped
        // product's figure hidden when it comes back.
        $blind = $check->blind && $counted === null
            && in_array($line->status, [App\Models\StockCheckLine::PENDING, App\Models\StockCheckLine::SKIPPED], true);
        $answered = in_array($line->status, [App\Models\StockCheckLine::CONFIRMED, App\Models\StockCheckLine::WRONG], true);
        $locked = $line->stock_adjustment_id !== null || $line->fix === App\Models\StockCheckLine::FIX_FIXED;
        $wrongOpen = $counted !== null;
        $unit = $product->unit;
        // ⚠️ One variable: Blade's json directive splits its argument on commas.
        $differenceWords = [
            'more' => __(':n more than the system says'),
            'fewer' => __(':n fewer than the system says'),
            'same' => __('The same as the system — this is a confirm.'),
        ];
    @endphp

    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
        <div class="flex-grow-1" style="min-width: 14rem">
            @include('stock-checks.partials.progress', ['progress' => $progress])
        </div>
        <form action="{{ route('stock-checks.scan', $check) }}" method="GET" class="d-flex gap-2" role="search">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                <input type="search" name="code" class="form-control" dir="ltr" autocomplete="off"
                       placeholder="{{ __('Scan or type a code') }}" aria-label="{{ __('Scan or type a code') }}"
                       @if(! $blind && ! $wrongOpen) autofocus @endif>
            </div>
        </form>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="min-w-0">
                            <a href="{{ route('products.show', $product) }}" target="_blank" class="h5 text-decoration-none d-block mb-1" dir="auto">{{ $product->name }}</a>
                            <div class="small text-secondary">
                                <span class="app-code" dir="ltr">{{ $product->sku }}</span>
                                @if($product->barcode)
                                    · <span class="app-code" dir="ltr">{{ $product->barcode }}</span>
                                @endif
                                @if($product->category)
                                    · {{ $product->category->name }}
                                @endif
                            </div>
                        </div>
                    </div>

                    @unless($blind)
                        <div class="mt-3">
                            <div class="small text-secondary">{{ __('What the system says') }}</div>
                            <div class="display-6 fw-semibold" data-system>{{ qty($system, $unit) }}</div>
                            @if($rooms->count() > 1)
                                <div class="small text-secondary">
                                    @foreach($rooms as $room => $held)
                                        {{ $room }}: {{ qty($held, $unit) }}@if(! $loop->last) · @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endunless
                </div>
            </div>

            {{-- What this product already says in this round. --}}
            @if($answered)
                <div class="alert {{ $line->status === App\Models\StockCheckLine::CONFIRMED ? 'alert-success' : 'alert-warning' }}">
                    @if($line->status === App\Models\StockCheckLine::CONFIRMED)
                        {{ __('Confirmed: :count on the shelf, by :name.', ['count' => qty($line->counted, $unit), 'name' => $line->checker?->name]) }}
                    @else
                        {{ __('Wrong: the system said :expected, the shelf had :counted.', ['expected' => qty($line->expected, $unit), 'counted' => qty($line->counted, $unit)]) }}
                        @if($line->adjustment)
                            {{ __('Put right by') }} <x-document-link :document="$line->adjustment" :kind="false" />.
                        @elseif($line->fix === App\Models\StockCheckLine::FIX_FIXED)
                            {{ __('Marked fixed.') }}
                        @else
                            {{ __('Still to fix.') }}
                        @endif
                    @endif
                    @unless($locked)
                        <div class="small mt-1">{{ __('Count it again to change the answer.') }}</div>
                    @endunless
                </div>
            @endif

            @if($check->isFinished())
                <div class="alert alert-secondary">{{ __('This stock check is finished.') }}</div>
            @elseif(! $locked)
                <div class="card">
                    <div class="card-body">
                        @if($blind)
                            {{-- Blind: the count first, then everything else. --}}
                            <form action="{{ route('stock-checks.count', [$check, $line]) }}" method="POST">
                                @csrf
                                <input type="hidden" name="seen" value="{{ $system }}">
                                <label for="blind-count" class="form-label fw-semibold">{{ __('How many are on the shelf?') }}</label>
                                <div class="input-group input-group-lg mb-2">
                                    <input id="blind-count" type="number" name="counted" min="0" step="1" required autofocus
                                           class="form-control" inputmode="numeric">
                                    <span class="input-group-text">{{ $unit }}</span>
                                </div>
                                <button type="submit" class="btn btn-primary btn-lg w-100">{{ __('Count') }}</button>
                            </form>
                        @else
                            @if($counted !== null)
                                <div class="alert alert-warning">
                                    {{ __('You counted :counted; the system says :system.', ['counted' => qty($counted, $unit), 'system' => qty($system, $unit)]) }}
                                </div>
                            @endif

                            <div class="d-grid gap-2">
                                @if($counted === null)
                                    <form action="{{ route('stock-checks.confirm', [$check, $line]) }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="seen" value="{{ $system }}">
                                        <button type="submit" class="btn btn-success btn-lg w-100">
                                            <i class="bi bi-check2-circle me-1"></i>{{ __('Confirm — the shelf agrees') }}
                                        </button>
                                    </form>
                                @endif
                                <button type="button" class="btn btn-outline-danger btn-lg {{ $wrongOpen ? 'd-none' : '' }}" data-wrong-open>
                                    <i class="bi bi-x-circle me-1"></i>{{ __('Wrong — count it') }}
                                </button>
                            </div>

                            <form action="{{ route('stock-checks.answer', [$check, $line]) }}" method="POST"
                                  class="mt-3 {{ $wrongOpen ? '' : 'd-none' }}" data-wrong-form data-guard-submit
                                  data-system="{{ $system }}">
                                @csrf
                                <input type="hidden" name="seen" value="{{ $system }}">
                                <label for="counted" class="form-label fw-semibold">{{ __('How many are on the shelf?') }}</label>
                                <div class="input-group input-group-lg mb-1">
                                    <input id="counted" type="number" name="counted" min="0" step="1" required
                                           class="form-control" inputmode="numeric" value="{{ $counted }}"
                                           @if($wrongOpen) autofocus @endif data-counted>
                                    <span class="input-group-text">{{ $unit }}</span>
                                </div>
                                <div class="small mb-3" data-difference></div>

                                {{-- Hidden while the count typed agrees: then it is a confirm, and nothing moves. --}}
                                <div data-when-different>
                                    @if($check->isSent())
                                        {{-- Soran, 2026-10-30: the counter counts, the admin fixes. --}}
                                        <input type="hidden" name="fix" value="later" data-fix>
                                        <div class="form-text mb-2">
                                            {{ __('A wrong count goes on the To fix list, and :name puts it right.', ['name' => $check->user->name]) }}
                                        </div>
                                    @else
                                    <div class="mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="fix" id="fix-adjusted" value="adjusted"
                                                   @checked($canAdjust) @disabled(! $canAdjust) data-fix>
                                            <label class="form-check-label" for="fix-adjusted">{{ __('Make the ADJ now') }}</label>
                                            @unless($canAdjust)
                                                <div class="form-text">
                                                    {{ $check->scope === App\Models\StockCheck::SCOPE_ROOM
                                                        ? __('An adjustment acts on the main room. Move the units with a stock move first, then adjust, and mark this fixed.')
                                                        : __('Making an adjustment needs the permission to create stock adjustments.') }}
                                                </div>
                                            @endunless
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="fix" id="fix-later" value="later"
                                                   @checked(! $canAdjust) data-fix>
                                            <label class="form-check-label" for="fix-later">{{ __('Fix later — put it on the To fix list') }}</label>
                                        </div>
                                    </div>
                                    @endif

                                    <div class="mb-2 d-none" data-cost>
                                        <label for="unit_cost" class="form-label small">{{ __('Cost each of the extra units') }}</label>
                                        <x-money-input name="unit_cost" :value="$latestCost" :lens="$lens" min="0" />
                                        <div class="form-text">{{ __('The latest batch cost. Change it if it is wrong.') }}</div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="note" class="form-label small">{{ __('Note') }}</label>
                                        <input id="note" type="text" name="note" maxlength="500" class="form-control" dir="auto"
                                               placeholder="{{ __('Hawkar has one, needs a return') }}">
                                    </div>
                                </div>

                                {{-- The words in their own span: the hold wraps the button's
                                     content, and only this span's text is changed here. --}}
                                <button type="submit" class="btn btn-danger w-100" data-save>
                                    <span data-save-words data-wrong="{{ __('Save the count') }}" data-agrees="{{ __('Confirm — the shelf agrees') }}">{{ __('Save the count') }}</span>
                                </button>
                            </form>
                        @endif

                        @if(! $answered)
                            <form action="{{ route('stock-checks.skip', [$check, $line]) }}" method="POST" class="mt-2">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary w-100">
                                    {{ __('Skip — show another product') }} <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            <div class="mt-3 d-flex gap-2">
                <a href="{{ route('stock-checks.next', $check) }}" class="btn btn-outline-primary">{{ __('Next product') }}</a>
                <a href="{{ route('stock-checks.show', $check) }}" class="btn btn-outline-secondary">{{ __('Report') }}</a>
            </div>
        </div>

        <div class="col-lg-7">
            @unless($blind)
                <div class="card mb-3">
                    <div class="card-header">{{ __('Open batches') }}</div>
                    @if($batches->isEmpty())
                        <x-empty-state icon="layers" :message="__('No stock in any batch.')" />
                    @else
                        {{-- ⚠️ overflow-y-hidden: rows of 32.5px round to one pixel more
                             than the box, and Windows drew a scrollbar with arrows for
                             it (Soran's screenshot, 2026-10-30). It scrolls sideways only. --}}
                        <div class="table-responsive overflow-y-hidden">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>{{ __('Received') }}</th>
                                    <th>{{ __('Source') }}</th>
                                    <th class="money">{{ __('Remaining') }}</th>
                                    <th class="money">{{ __('Cost each') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($batches as $batch)
                                    <tr>
                                        <td class="small"><span class="app-code">{{ $batch->received_at->format('Y-m-d') }}</span></td>
                                        <td class="small"><x-document-link :document="$batch->source" :type="$batch->source_type" :id="$batch->source_id" /></td>
                                        <td class="money fw-semibold">{{ qty($batch->quantity_remaining, $unit) }}</td>
                                        <td class="money text-secondary">{{ cost_money($batch->unit_cost, false, $lens) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="card mb-3">
                    <div class="card-header">{{ __('Last movements') }}</div>
                    @if($movements->isEmpty())
                        <x-empty-state icon="arrow-left-right" :message="__('Nothing has moved yet.')" />
                    @else
                        <div class="table-responsive overflow-y-hidden">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                @foreach($movements as $movement)
                                    <tr>
                                        <td class="small"><span class="app-code">{{ $movement->occurred_at->format('Y-m-d H:i') }}</span></td>
                                        <td class="small"><x-document-link :document="$movement->reference" :type="$movement->reference_type" :id="$movement->reference_id" /></td>
                                        <td class="money fw-semibold {{ $movement->quantity > 0 ? 'text-success' : 'text-danger' }}">
                                            {{ $movement->quantity > 0 ? '+' : '' }}{{ qty($movement->quantity, $unit) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="small text-secondary">
                    @if($lastCheck)
                        {{ __('Last checked :when by :name in :number: :answer.', [
                            'when' => $lastCheck->checked_at->diffForHumans(),
                            'name' => $lastCheck->checker?->name,
                            'number' => $lastCheck->stockCheck->document_no,
                            'answer' => $lastCheck->status === App\Models\StockCheckLine::CONFIRMED ? __('confirmed') : __('wrong'),
                        ]) }}
                    @else
                        {{ __('Never checked before.') }}
                    @endif
                </div>
            @endunless
        </div>
    </div>

    {{-- Wrong: the difference as it is typed, and the cost box only for units
         the system does not have. --}}
    <script>
        (() => {
            const open = document.querySelector('[data-wrong-open]');
            const form = document.querySelector('[data-wrong-form]');
            if (!form) return;
            const system = Number(form.dataset.system);
            const counted = form.querySelector('[data-counted]');
            const said = form.querySelector('[data-difference]');
            const cost = form.querySelector('[data-cost]');
            const different = form.querySelector('[data-when-different]');
            const button = form.querySelector('[data-save]');
            const buttonWords = form.querySelector('[data-save-words]');
            const words = @json($differenceWords);

            const show = () => {
                const typed = counted.value === '' ? null : Number(counted.value);
                const difference = typed === null ? 0 : typed - system;
                said.textContent = typed === null ? '' : (difference === 0 ? words.same
                    : (difference > 0 ? words.more : words.fewer).replace(':n', Math.abs(difference)));
                said.className = 'small mb-3 ' + (difference > 0 ? 'text-success' : difference < 0 ? 'text-danger' : 'text-secondary');
                const adjust = form.querySelector('[data-fix]:checked')?.value === 'adjusted';
                cost.classList.toggle('d-none', !(adjust && difference > 0));

                // ⚠️ A count that agrees is a confirm, whatever is ticked: the
                // screen says so, and hides what would not happen (Soran,
                // 2026-10-30: "are i type 4 and click on save … create new adj").
                const agrees = typed !== null && difference === 0;
                different.classList.toggle('d-none', agrees);
                button.classList.toggle('btn-danger', !agrees);
                button.classList.toggle('btn-success', agrees);
                buttonWords.textContent = agrees ? buttonWords.dataset.agrees : buttonWords.dataset.wrong;
            };

            open?.addEventListener('click', () => {
                open.classList.add('d-none');
                form.classList.remove('d-none');
                counted.focus();
            });
            form.addEventListener('input', show);
            form.addEventListener('change', show);
            show();
        })();
    </script>
@endsection
