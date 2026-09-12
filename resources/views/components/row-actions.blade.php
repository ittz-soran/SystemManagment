@props([
    'view' => null,

    /*
     * Printing, straight from the list.
     *
     * Added 2026-09-12 when the eye button went. The eye had been the row's only
     * action on the sale, purchase and return lists, and once the document
     * number itself became the link it was a second copy of that link in the
     * place nobody looks first — but taking it away left an empty column with a
     * heading over it, which is worse than the redundancy was.
     *
     * Print is what belongs there instead. It is the one thing a shop does to a
     * finished document from a list — hand the customer their invoice — and
     * doing it without opening the record first saves the trip.
     */
    'print' => null,
    'edit' => null,
    // Some records are edited in a modal rather than on a page of their own —
    // a supplier is three fields, and a whole screen for them would be a page
    // that exists only to be left again. Pass the modal's selector and the
    // values it should open with, and the pencil opens that instead of
    // following a link.
    'editModal' => null,
    'editData' => [],
    'state' => null,
    'deleteState' => null,
    'delete' => null,
    'deleteLabel' => null,
])

{{-- Section 9b: "View always; Edit/Delete only when unlocked — otherwise render
     them disabled with the lock reason as a tooltip. Never hide them, or Soran
     will think the feature is missing." --}}
@php
    $locked = $state && ! $state['allowed'];

    // Delete can be locked when edit is not — a purchase whose stock has been
    // sold may still be corrected, but no longer removed.
    $deleteState ??= $state;
    $deleteLocked = $deleteState && ! $deleteState['allowed'];
@endphp

<div class="btn-group btn-group-sm app-row-actions">
    @if($view)
        <a href="{{ $view }}" class="btn btn-outline-secondary" title="{{ __('View') }}">
            <i class="bi bi-eye"></i>
        </a>
    @endif

    @if($print)
        {{-- A new tab: the printed sheet replaces the page otherwise, and the
             shopkeeper's place in a filtered, paged list is gone with it. --}}
        <a href="{{ $print }}" target="_blank" rel="noopener"
           class="btn btn-outline-secondary" title="{{ __('Print') }}">
            <i class="bi bi-printer"></i>
        </a>
    @endif

    @if($edit || $editModal)
        @if($locked)
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $state['reason'] }}">
                <button class="btn btn-outline-secondary" disabled><i class="bi bi-pencil"></i></button>
            </span>
        @elseif($editModal)
            <button type="button" class="btn btn-outline-secondary" title="{{ __('Edit') }}"
                    data-bs-toggle="modal" data-bs-target="{{ $editModal }}"
                    @foreach($editData as $key => $value) data-{{ $key }}="{{ $value }}" @endforeach>
                <i class="bi bi-pencil"></i>
            </button>
        @else
            <a href="{{ $edit }}" class="btn btn-outline-secondary" title="{{ __('Edit') }}">
                <i class="bi bi-pencil"></i>
            </a>
        @endif
    @endif

    @if($delete)
        @if($deleteLocked)
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $deleteState['reason'] }}">
                <button class="btn btn-outline-danger" disabled><i class="bi bi-trash"></i></button>
            </span>
        @else
            <form action="{{ $delete }}" method="POST" class="d-inline"
                  onsubmit="return confirm(@js($deleteLabel ?? __('Delete this record?')))">
                @csrf
                @method('DELETE')
                <x-return-to />
                <button type="submit" class="btn btn-outline-danger" title="{{ __('Delete') }}">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
        @endif
    @endif
</div>
