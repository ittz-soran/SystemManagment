{{--
    A round's report, on its page and on paper — PROJECT_DOC Section 9, Stock
    check. ⚠️ A value is shown only to a reader who may see cost; an ADJ's is
    its true FIFO cost, and a difference not yet adjusted is an estimate at
    the latest batch's cost and says so.
--}}
<div class="card mb-4">
    <div class="card-header">{{ __('Wrong') }} ({{ $wrong->count() }})</div>
    @if($wrong->isEmpty())
        <x-empty-state icon="check-circle" :message="__('Nothing was found wrong in this round.')" />
    @else
        <div class="table-responsive overflow-y-hidden">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th class="money">{{ __('System') }}</th>
                    <th class="money">{{ __('Shelf') }}</th>
                    <th class="money">{{ __('Difference') }}</th>
                    @if($seesCost)
                        <th class="money">{{ __('Value at cost') }}</th>
                    @endif
                    <th>{{ __('Put right') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($wrong as $line)
                    @php
                        $difference = $line->difference();
                        $value = $values[$line->id] ?? null;
                    @endphp
                    <tr>
                        <td dir="auto">
                            {{ $line->product->name }}
                            <div class="small text-secondary"><span class="app-code" dir="ltr">{{ $line->product->sku }}</span></div>
                        </td>
                        <td class="money">{{ qty($line->expected, $line->product->unit) }}</td>
                        <td class="money">{{ qty($line->counted, $line->product->unit) }}</td>
                        <td class="money fw-semibold {{ $difference > 0 ? 'text-success' : 'text-danger' }}">
                            {{ $difference > 0 ? '+' : '−' }}{{ number_format(abs($difference)) }}
                        </td>
                        @if($seesCost)
                            <td class="money">
                                @if($value)
                                    {{ $value['value'] < 0 ? '−' : '' }}{{ money(abs($value['value']), false, $lens) }}
                                    @if($value['estimate'])
                                        <div class="small text-secondary">{{ __('estimate') }}</div>
                                    @endif
                                @endif
                            </td>
                        @endif
                        <td class="small">
                            @if($line->adjustment)
                                <x-document-link :document="$line->adjustment" :kind="false" />
                            @elseif($line->fix === App\Models\StockCheckLine::FIX_FIXED)
                                <span class="badge text-bg-success">{{ __('Fixed') }}</span>
                            @else
                                <span class="badge text-bg-warning">{{ __('To fix') }}</span>
                            @endif
                            @if($line->note)
                                <div class="text-secondary" dir="auto">{{ $line->note }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{--
    A blind count answered differently from its first count — PROJECT_DOC
    Section 9, the first count. The figure is shown after one count, so this
    is where a count copied from the screen shows: first 3, answered 4, the
    system 4.
--}}
@if($recounted->isNotEmpty())
    <div class="card mb-4" data-recounted>
        <div class="card-header">{{ __('Counted again') }} ({{ $recounted->count() }})</div>
        <div class="card-body small text-secondary py-2">
            {{ __('A blind count that did not agree the first time. The system\'s figure was shown after it, and the answer below was given after that.') }}
        </div>
        <div class="table-responsive overflow-y-hidden">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th class="money">{{ __('First count') }}</th>
                    <th class="money">{{ __('Answer') }}</th>
                    <th class="money">{{ __('System') }}</th>
                    <th>{{ __('Counted by') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($recounted as $line)
                    <tr>
                        <td dir="auto">{{ $line->product->name }}</td>
                        <td class="money" data-first-count>{{ qty($line->first_count, $line->product->unit) }}</td>
                        <td class="money fw-semibold">{{ qty($line->counted, $line->product->unit) }}</td>
                        <td class="money">{{ qty($line->expected, $line->product->unit) }}</td>
                        <td class="small">{{ $line->checker?->name }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-header">{{ __('Not checked') }} ({{ $unchecked->count() }})</div>
    @if($unchecked->isEmpty())
        <x-empty-state icon="check-circle" :message="__('Every product in this round has an answer.')" />
    @else
        <ul class="list-group list-group-flush">
            @foreach($unchecked as $line)
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span dir="auto">{{ $line->product->name }}</span>
                    @if($line->status === App\Models\StockCheckLine::SKIPPED)
                        <span class="badge text-bg-warning">{{ __('Skipped') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
