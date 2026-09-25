@props([
    // Where "Clear" goes back to, and where the form posts.
    'action',

    // The prefix a document of this kind carries — SRT-, PRT-, SWP-. It is the
    // placeholder, because the one thing anybody types into this box is a
    // document number and the prefix is the half they never remember.
    'prefix' => '',
])

{{--
    The filter row the three "something came back" lists share — Soran,
    2026-09-25: *"make all three purchase-returns, sale-returns, swaps have
    same designs or same like one"*.

    ⚠️ **One component rather than three copies, and that is the whole point.**
    Both return lists carried this markup word for word and the swap list
    carried none of it — which nobody noticed for two days, because there was
    nothing visibly different from it. A copy that drifts is invisible; a
    component that is missing is a blank space on the page.
--}}
<form method="GET" action="{{ $action }}" class="card card-body mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label for="search" class="form-label small">{{ __('Document number') }}</label>
            <input id="search" type="search" name="search" value="{{ request('search') }}"
                   class="form-control form-control-sm" placeholder="{{ $prefix }}" dir="ltr">
        </div>
        <div class="col-md-2">
            <label for="from" class="form-label small">{{ __('From') }}</label>
            <input id="from" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-2">
            <label for="to" class="form-label small">{{ __('To') }}</label>
            <input id="to" type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
            <a href="{{ $action }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
        </div>
        <div class="col-12">
            <x-date-presets />
        </div>
    </div>

    {{-- ⚠️ Carried through the filter, or narrowing a date range silently puts
         the archived period back out of sight and the notice above flips from
         "showing them" to "hidden" with nothing on the page having said so. --}}
    @if(request()->boolean('archived'))
        <input type="hidden" name="archived" value="1">
    @endif
</form>
