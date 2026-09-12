{{--
    Today, this month, last month, this year — one press instead of two dates.

    **Asked for by Soran, 2026-09-12:** *"in those pages have show data add more
    filtering option like today, this month…"*. Every list already filters by a
    date range; what it wanted was the three or four ranges anybody actually
    asks for, without typing them.

    They are links rather than buttons, and they carry the rest of the query
    with them: somebody who has narrowed to one customer and then presses
    "This month" means *this month, for that customer* — dropping the customer
    would answer a question they did not ask.

    ⚠️ There is no "this week" on purpose. The week starts on Saturday in Iraq
    and on Monday in Carbon's default, and a preset that quietly means a
    different seven days to the shopkeeper than to the code is worse than no
    preset. It can be added the day the shop's own week-start is a setting.
--}}
@props([
    'from' => 'from',   // the query keys this list uses for its range
    'to' => 'to',
])

@php
    $today = today();

    $ranges = [
        __('Today') => [$today, $today],
        __('This month') => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        // subMonthNoOverflow, or the 31st of March asks for the 31st of February
        // and Carbon answers with the 3rd of March.
        __('Last month') => [
            $today->copy()->subMonthNoOverflow()->startOfMonth(),
            $today->copy()->subMonthNoOverflow()->endOfMonth(),
        ],
        __('This year') => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
    ];
@endphp

<div class="d-flex flex-wrap gap-1 align-items-center">
    @foreach($ranges as $label => [$start, $end])
        @php
            $isOn = request($from) === $start->toDateString() && request($to) === $end->toDateString();
        @endphp

        <a href="{{ request()->fullUrlWithQuery([$from => $start->toDateString(), $to => $end->toDateString()]) }}"
           class="btn btn-sm {{ $isOn ? 'btn-secondary' : 'btn-outline-secondary' }}"
           @if($isOn) aria-current="true" @endif>{{ $label }}</a>
    @endforeach

    {{-- Only once a range is actually set: an always-present Clear on a list
         that is not filtered is a button that does nothing. --}}
    @if(request($from) || request($to))
        <a href="{{ request()->fullUrlWithQuery([$from => null, $to => null]) }}"
           class="btn btn-sm btn-link text-decoration-none">{{ __('Any date') }}</a>
    @endif
</div>
